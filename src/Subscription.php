<?php

declare(strict_types=1);

namespace Drupal\authorize_subscription;

use Drupal\authorize_subscription\Plugin\Commerce\PaymentGateway\AuthorizeSubscription;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

/**
 * @todo Add class description.
 */
final class Subscription {

    /**
     * Cancel subscription on Authorize.
     */
    public function cancel(\Drupal\Core\Entity\EntityInterface $entity): void {
        $order = $entity->initial_order->entity;
        $payment = array_values(\Drupal::entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order))[0];
        $paymentGateway = $payment->payment_gateway->entity;
        $paymentConfig = $paymentGateway->getPluginConfiguration();
        $subscriptionId = $payment->getRemoteId();

        /* Create a merchantAuthenticationType object with authentication details
          retrieved from the constants file */

        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($paymentConfig['api_login']);
        $merchantAuthentication->setTransactionKey($paymentConfig['transaction_key']);

        // Set the transaction's refId
        $refId = $order->id();

        $request = new AnetAPI\ARBCancelSubscriptionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setSubscriptionId($subscriptionId);

        $controller = new AnetController\ARBCancelSubscriptionController($request);

        $response = null;
        if ($paymentConfig['mode'] === 'test') {
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else{
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }
        $currentUser = \Drupal::currentUser();

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
            \Drupal::logger('authorize_subscription')->notice('Canceled subscription: order @order_id, user @uid subscription id: @subs_id response: @response',
                    [
                        '@order_id' => $order->id(),
                        '@uid' => $currentUser->id(),
                        '@subs_id' => $subscriptionId,
                        '@response' => json_encode($response)
            ]);
        } else {

            $error = $response->getMessages()->getMessage();
            $errorMessages .= "ERROR :  Invalid response\n";
            $errorMessages .= "Response : " . $error[0]->getCode() . "  " . $error[0]->getText() . ": order @order_id, user @uid subscription id: @subs_id.";
            \Drupal::logger('authorize_subscription')->notice($errorMessages,
                    [
                        '@order_id' => $order->id(),
                        '@uid' => $currentUser->id(),
                        '@subs_id' => $subscriptionId
            ]);
        }
    }

    /**
     * Get subscription status on Authorize.
     */
    public function getStatus(\Drupal\Core\Entity\EntityInterface $entity): string | bool {
        $order = $entity->initial_order->entity;
        $payment = array_values(\Drupal::entityTypeManager()->getStorage('commerce_payment')->loadMultipleByOrder($order))[0];
        $paymentGateway = $payment->payment_gateway->entity;
        $paymentConfig = $paymentGateway->getPluginConfiguration();
        $subscriptionId = $payment->getRemoteId();

        /* Create a merchantAuthenticationType object with authentication details
          retrieved from the constants file */

        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($paymentConfig['api_login']);
        $merchantAuthentication->setTransactionKey($paymentConfig['transaction_key']);

        // Set the transaction's refId
        $refId = $order->id();

        $request = new AnetAPI\ARBGetSubscriptionStatusRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setSubscriptionId($subscriptionId);

        $controller = new AnetController\ARBGetSubscriptionStatusController($request);

        $response = null;
        if ($paymentConfig['mode'] === 'test') {
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else{
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }
        $currentUser = \Drupal::currentUser();

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
            \Drupal::logger('authorize_subscription')->notice('Subscription status: order @order_id, user @uid subscription id: @subs_id response: @response',
                    [
                        '@order_id' => $order->id(),
                        '@uid' => $currentUser->id(),
                        '@subs_id' => $subscriptionId,
                        '@response' => json_encode($response)
            ]);
            $status = $response->getStatus();
            if(is_null($status))
            {
                return false;
            }
            return($status);
            
        } else {

            $error = $response->getMessages()->getMessage();
            $errorMessages .= "ERROR :  Invalid response\n";
            $errorMessages .= "Response : " . $error[0]->getCode() . "  " . $error[0]->getText() . ": order @order_id, user @uid subscription id: @subs_id.";
            \Drupal::logger('authorize_subscription')->notice($errorMessages,
                    [
                        '@order_id' => $order->id(),
                        '@uid' => $currentUser->id(),
                        '@subs_id' => $subscriptionId
            ]);
        }
        return false;
    }

}
