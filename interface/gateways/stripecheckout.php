<?php


// Give webhook time to load
sleep( .5 );

\define('REPORT_EXCEPTIONS', TRUE);
require_once '../../../../init.php';
\IPS\Session\Front::i();
\IPS\IPS::$PSR0Namespaces['StripeAPI'] = 'IPS\applications\cbpanel\sources\StripeAPI';

/**
 * Stripe Webhook Handler
 */
class stripeCheckoutWebhookHandler
{
    /**
     * @brief	Raw Webhook Data (as a string)
     */
    private $body;

    /**
     * @brief	Parsed Webhook Data (as an array)
     */
    private $data;

    /**
     * @brief	Transaction
     */
    private $transaction;

    /**
     * Constructor
     *
     * @param	string	$body	The raw body posted to this script
     * @return	void
     */
    public function __construct( $body )
    {
        $this->body = $body;
        $this->data = json_decode( $body, TRUE );
    }

    /**
     * A source has become chargeable (for example, 3DSecure was completed) so we're ready to authorize the payment
     *
     * @return	string
     * @throws
     */
    private function sourceChargeable()
    {
        /* Don't need to do anything for cards, since this is only for asynchronous payments */
        if ( isset( $this->data['data']['object']['type'] ) and \in_array( $this->data['data']['object']['type'], array( 'card' ) ) )
        {
            return 'NO_PROCESSING_REQUIRED';
        }

        /* Check the status */
        if ( !\in_array( $this->transaction->status, array( \IPS\nexus\Transaction::STATUS_PENDING, \IPS\nexus\Transaction::STATUS_WAITING ) ) )
        {
            if ( \in_array( $this->transaction->status, array( \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING, \IPS\nexus\Transaction::STATUS_PAID ) ) )
            {
                return 'ALREADY_PROCESSED';
            }
            else
            {
                throw new \Exception('BAD_STATUS');
            }
        }

        /* Validate the source with Stripe */
        $source = $this->transaction->method->api( 'sources/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['id'] ) );
        if ( $source['client_secret'] != $this->data['data']['object']['client_secret'] )
        {
            throw new \Exception('BAD_SECRET');
        }

        /* Check we're not just going to refuse this */
        $maxMind = NULL;
        if ( \IPS\Settings::i()->maxmind_key and ( !\IPS\Settings::i()->maxmind_gateways or \IPS\Settings::i()->maxmind_gateways == '*' or \in_array( $this->transaction->method->id, explode( ',', \IPS\Settings::i()->maxmind_gateways ) ) ) )
        {
            $maxMind = new \IPS\nexus\Fraud\MaxMind\Request( FALSE );
            $maxMind->setIpAddress( $this->transaction->ip );
            $maxMind->setTransaction( $this->transaction );
        }
        $fraudResult = $this->transaction->runFraudCheck( $maxMind );
        if ( $fraudResult === \IPS\nexus\Transaction::STATUS_REFUSED )
        {
            $this->transaction->executeFraudAction( $fraudResult, FALSE );
            $this->transaction->sendNotification();
        }

        /* Authorize */
        else
        {
            $this->transaction->auth = $this->transaction->method->auth( $this->transaction, array( $this->transaction->method->id . '_card' => $source['id'] ) );
            $this->transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
            $this->transaction->save();
        }

        /* Return */
        return 'OK';
    }

    /**
     * A charge was successful so we can approve the transaction
     *
     * @return	string
     */
    private function chargeSucceeded()
    {
        if ( $this->transaction->status === \IPS\nexus\Transaction::STATUS_PAID )
        {
            return 'ALREADY_PROCESSED';
        }

        /* Validate the charge with Stripe */
        try
        {
            $charge = $this->transaction->method->api( 'charges/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['id'] ) );
            if ( !\in_array( $charge['status'], array( 'succeeded', 'paid' ) ) )
            {
                throw new \Exception;
            }
        }
        catch ( \Exception $e )
        {
            throw new \Exception('INVALID_CHARGE');
        }

        /* Create a MaxMind request */
        $maxMind = NULL;
        if ( \IPS\Settings::i()->maxmind_key and ( !\IPS\Settings::i()->maxmind_gateways or \IPS\Settings::i()->maxmind_gateways == '*' or \in_array( $this->transaction->method->id, explode( ',', \IPS\Settings::i()->maxmind_gateways ) ) ) )
        {
            $maxMind = new \IPS\nexus\Fraud\MaxMind\Request( FALSE );
            $maxMind->setIpAddress( $this->transaction->ip );
            $maxMind->setTransaction( $this->transaction );
        }

        /* Check fraud rules */
        $fraudResult = $this->transaction->runFraudCheck( $maxMind );
        if ( $fraudResult )
        {
            $this->transaction->executeFraudAction( $fraudResult, TRUE );
        }

        /* If we're not being fraud blocked, we can approve */
        if ( $fraudResult === \IPS\nexus\Transaction::STATUS_PAID )
        {
            $this->transaction->member->log( 'transaction', array(
                'type'			=> 'paid',
                'status'		=> \IPS\nexus\Transaction::STATUS_PAID,
                'id'			=> $this->transaction->id,
                'invoice_id'	=> $this->transaction->invoice->id,
                'invoice_title'	=> $this->transaction->invoice->title,
            ) );
            $this->transaction->approve();
        }

        /* Either way, let the user know we got their payment */
        $this->transaction->sendNotification();

        /* Return */
        return 'OK';
    }

    /**
     * A charge failed so we need to mark the transaction as failed locally
     *
     * @return	string
     */
    private function chargeFailed()
    {

        if ( $this->transaction->status === \IPS\nexus\Transaction::STATUS_REFUSED )
        {
            return 'ALREADY_PROCESSED';
        }


        /* Validate the charge with Stripe */
        try
        {
            $charge = $this->transaction->method->api( 'charges/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['id'] ) );
            if ( $charge['status'] !== 'failed' )
            {
                throw new \Exception;
            }
        }
        catch ( \Exception $e )
        {
            throw new \Exception('INVALID_CHARGE');
        }

        /* Mark it failed */
        $this->transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
        $extra = $this->transaction->extra;
        $extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_REFUSED, 'noteRaw' => $this->data['data']['object']['failure_message'] );
        $this->transaction->extra = $extra;
        $this->transaction->save();
        $this->transaction->member->log( 'transaction', array(
            'type'			=> 'paid',
            'status'		=> \IPS\nexus\Transaction::STATUS_REFUSED,
            'id'			=> $this->transaction->id,
            'invoice_id'	=> $this->transaction->invoice->id,
            'invoice_title'	=> $this->transaction->title,
        ), FALSE );

        /* Send notification */
        $this->transaction->sendNotification();

        /* Return */
        return 'OK';
    }

    /**
     * A chargeback/dispute has been made against a transaction so we need to mark it as such locally
     *
     * @return	string
     */
    private function disputeCreated()
    {
        /* Validate the dispute with Stripe */
        try
        {
            $dispute = $this->transaction->method->api( 'disputes/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['id'] ) );
            if ( !\in_array( $dispute['status'], array( 'needs_response', 'warning_needs_response' ) ) )
            {
                throw new \Exception;
            }

            if ( \substr( $this->transaction->gw_id, 0, 3 ) === 'pi_' )
            {
                $paymentIntent = $this->transaction->method->api( 'payment_intents/' . $this->transaction->gw_id );

                $ok = FALSE;
                foreach ( $paymentIntent['charges']['data'] as $charge )
                {
                    if ( $dispute['charge'] === $charge['id'] )
                    {
                        $ok = TRUE;
                        break;
                    }
                }

                if ( !$ok )
                {
                    throw new \Exception;
                }
            }
            elseif ( $dispute['charge'] !== $this->transaction->gw_id )
            {
                throw new \Exception;
            }
        }
        catch ( \Exception $e )
        {
            throw new \Exception('INVALID_DISPUTE');
        }

        /* Mark the transaction as disputed */
        $this->transaction->status = \IPS\nexus\Transaction::STATUS_DISPUTED;
        $extra = $this->transaction->extra;
        $extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_DISPUTED, 'on' => $this->data['data']['object']['created'], 'ref' => $this->data['data']['object']['id'] );
        $this->transaction->extra = $extra;
        $this->transaction->save();

        /* Log */
        if ( $this->transaction->member )
        {
            $this->transaction->member->log( 'transaction', array(
                'type'		=> 'status',
                'status'	=> \IPS\nexus\Transaction::STATUS_DISPUTED,
                'id'		=> $this->transaction->id
            ) );
        }

        /* Mark the invoice as not paid (revoking benefits) */
        $this->transaction->invoice->markUnpaid( \IPS\nexus\Invoice::STATUS_CANCELED );

        /* Send admin notification */
        \IPS\core\AdminNotification::send( 'nexus', 'Transaction', \IPS\nexus\Transaction::STATUS_DISPUTED, TRUE, $this->transaction );

        /* Return */
        return 'OK';
    }

    /**
     * A chargeback/dispute has been resolved (which may be won or lost)
     *
     * @return string
     * @throws Exception
     */
    private function disputeClosed()
    {
        /* Validate the dispute with Stripe */
        try
        {
            $dispute = $this->transaction->method->api( 'disputes/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['id'] ) );
            if ( \substr( $this->transaction->gw_id, 0, 3 ) === 'pi_' )
            {
                $paymentIntent = $this->transaction->method->api( 'payment_intents/' . $this->transaction->gw_id );

                $ok = FALSE;
                foreach ( $paymentIntent['charges']['data'] as $charge )
                {
                    if ( $dispute['charge'] === $charge['id'] )
                    {
                        $ok = TRUE;
                        break;
                    }
                }

                if ( !$ok )
                {
                    throw new \Exception;
                }
            }
            elseif ( $dispute['charge'] !== $this->transaction->gw_id )
            {
                throw new \Exception;
            }
        }
        catch ( \Exception $e )
        {
            throw new \Exception('INVALID_DISPUTE');
        }

        /* Did we win or lose? */
        if ( \in_array( $dispute['status'], array( 'won', 'warning_closed' ) ) )
        {
            // Do nothing
        }
        elseif ( \in_array( $dispute['status'], array( 'lost' ) ) )
        {
            $this->transaction->status = \IPS\nexus\Transaction::STATUS_REFUNDED;
            $this->transaction->save();
        }

        /* Return */
        return 'OK';
    }

    /**
     * Run
     *
     * @param string $signature The signature provided by this request
     * @return void
     * @throws Exception
     */
    public function run( $signature )
    {
        /* Ignore anything we don't care about */
        if ( !isset( $this->data['type'] ) or !\in_array( $this->data['type'], array( 'source.chargeable', 'charge.succeeded', 'charge.failed', 'charge.dispute.created', 'charge.dispute.closed' ) ) )
        {
            return 'UNNEEDED_TYPE';
        }

        /* Try to find the transaction this is about */
        try
        {
            if ( isset( $this->data['data']['object']['metadata']['Transaction ID'] ) )
            {
                $this->transaction = \IPS\nexus\Transaction::load( $this->data['data']['object']['metadata']['Transaction ID'] );
            }
            elseif ( isset( $this->data['data']['object']['redirect']['return_url'] ) )
            {
                $url = new \IPS\Http\Url( $this->data['data']['object']['redirect']['return_url'] );
                $this->transaction = \IPS\nexus\Transaction::load( $url->queryString['nexusTransactionId'] );
            }
            elseif ( isset( $this->data['data']['object']['charge'] ) )
            {
                $paymentIntentId = NULL;
                foreach ( \IPS\nexus\Gateway::roots() as $method )
                {
                    if ( $method instanceof \IPS\nexus\Gateway\StripeCheckout )
                    {
                        try
                        {
                            $charge = $method->api( 'charges/' . preg_replace( '/[^A-Z0-9_]/i', '', $this->data['data']['object']['charge'] ) );
                            if ( isset( $charge['payment_intent'] ) )
                            {
                                $paymentIntentId = $charge['payment_intent'];
                            }
                            break;
                        }
                        catch ( \Exception $e )
                        {
                            // Do nothing
                        }
                    }
                }

                if ( $paymentIntentId )
                {
                    $this->transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', array( '( t_gw_id=? OR t_gw_id=? )', $paymentIntentId, $this->data['data']['object']['charge'] ) )->first() );
                }
                else
                {
                    $this->transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', array( 't_gw_id=?', $this->data['data']['object']['charge'] ) )->first() );
                }
            }
            elseif ( isset( $this->data['data']['object']['id'] ) and mb_substr( $this->data['data']['object']['id'], 0, 4 ) !== 'src_' )
            {
                $where = array( array( 't_gw_id=?', $this->data['data']['object']['id'] ) );
                if ( isset( $this->data['data']['object']['metadata']['Invoice ID'] ) )
                {
                    $where[] = array( 't_invoice=?', $this->data['data']['object']['metadata']['Invoice ID'] );
                }

                $this->transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', $where )->first() );
            }
            else
            {
                return 'NO_TRANSACTION_INFORMATION';
            }
        }
        catch ( \Exception $e )
        {
            throw new \Exception('COULD_NOT_FIND_TRANSACTION');
        }

        /* Validate the signature */
        if ( !( $this->transaction->method instanceof \IPS\cbpanel\StripeCheckout ) )
        {
            throw new \Exception('INVALID_GATEWAY');
        }
        $settings = json_decode( $this->transaction->method->settings, TRUE );

        if ( isset( $settings['webhook_secret'] ) and $settings['webhook_secret'] )  // In case they upgraded and haven't provided one
        {
            $sig = array();
            foreach ( explode( ',', $signature ) as $row )
            {
                if ( \strpos( $row, '=' ) !== FALSE )
                {
                    list( $k, $v ) = explode( '=', trim( $row ) );
                    $sig[ trim( $k ) ][] = trim( $v );
                }
            }

            if ( isset( $sig['t'] ) and isset( $sig['t'][0] ) )
            {
                $signedPayload = $sig['t'][0] . '.' . $this->body;
                $signature = hash_hmac( 'sha256', $signedPayload, $settings['webhook_secret'] );

                if ( !\in_array( $signature, $sig['v1'] ) )
                {
                    throw new \Exception('INVALID_SIGNING_SECRET');
                }
            }
            else
            {
                throw new \Exception('INVALID_SIGNING_SECRET');
            }
        }

        /* Do it */
        if ( isset( $this->data['type'] ) )
        {
            switch ( $this->data['type'] )
            {
                case 'source.chargeable':
                    return $this->sourceChargeable();
                case 'charge.succeeded':
                    return $this->chargeSucceeded();
                case 'charge.failed':
                    return $this->chargeFailed();
                case 'charge.dispute.created':
                    return $this->disputeCreated();
                case 'charge.dispute.closed':
                    return $this->disputeClosed();
                default:
                    return 'UNNEEDED_TYPE';
            }
        }
    }
}

$class = new stripeCheckoutWebhookHandler( trim( @file_get_contents('php://input') ) );
try
{
    $response = $class->run( isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '' );
    \IPS\Output::i()->sendOutput( $response, 200, 'text/plain' );
}
catch ( \Exception $e )
{
    \IPS\Output::i()->sendOutput( $e->getMessage(), 500, 'text/plain' );
}