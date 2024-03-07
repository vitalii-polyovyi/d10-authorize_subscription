<?php

/**
 * Description of AuthorizeSubscription
 *
 * @author Denis
 */

namespace Drupal\authorize_subscription\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\AcceptJs;
use CommerceGuys\AuthNet\CreateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\CreateCustomerProfileRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CardholderAuthentication;
use CommerceGuys\AuthNet\DataTypes\CreditCard as AuthnetCreditCard;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\LineItem;
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\PaymentProfile;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\ShipTo;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use CommerceGuys\AuthNet\UpdateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\UpdateHeldTransactionRequest;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Locale\CountryManager;

/**
 * Provides the Authorize subscription onsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorize_subscription_checkout",
 *   label = @Translation("Authorize subscription"),
 *   display_label = @Translation("Authorize subscription"),
 *   forms = {
 *     "add-payment-method" = "Drupal\authorize_subscription\PluginForm\AcceptJs\PaymentMethodAddForm",
 *     "approve-payment" = "Drupal\authorize_subscription\PluginForm\AcceptJs\PaymentApproveForm",
 *     "decline-payment" = "Drupal\commerce_authnet\PluginForm\AcceptJs\PaymentDeclineForm",
 *   },
 *   payment_type = "acceptjs",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa", "unionpay"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
//  "amex", "dinersclub", "discover", "jcb", "mastercard", "visa", "unionpay"
class AuthorizeSubscription extends AcceptJs {

    /**
     * {@inheritdoc}
     */
    public function createPayment(PaymentInterface $payment, $capture = TRUE) {
        $tempstore = \Drupal::service('tempstore.private')->get('authorize_subscription');
        $payment_details['data_descriptor'] = $tempstore->get('data_descriptor');
        $payment_details['data_value'] = $tempstore->get('data_value');

        //  dsm('createPayment');
        //    dsm($payment_details);

        $this->assertPaymentState($payment, ['new']);
        $payment_method = $payment->getPaymentMethod();
        $this->assertPaymentMethod($payment_method);
        $order = $payment->getOrder();
        $owner = $payment_method->getOwner();
        //    dsm($payment_method);
        // @todo update SDK to support data type like this.
        // Initializing the profile to charge and adding it to the transaction.
        $customer_profile_id = $this->getRemoteCustomerId($owner);
        if (empty($customer_profile_id)) {
            $customer_profile_id = $this->getPaymentMethodCustomerId($payment_method);
        }
        $payment_profile_id = $this->getRemoteProfileId($payment_method);
        $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
        $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
        //  dsm( $order);
        $orderItems = $order->getItems();
        if (count($orderItems) === 0) {
            throw new PaymentGatewayException("No order items");
        }

        $productVariation = $orderItems[0]->getPurchasedEntity();

        $title = $productVariation->getTitle();
        $subscrProd = $productVariation->billing_schedule->entity;
        
        $unit = $subscrProd->getPluginConfiguration()['interval']['unit'];
        $unit .= 's'; //fix for moths...
        $occurrency = $subscrProd->getPluginConfiguration()['interval']['number'];

        // $transaction_request->addData('profile', $profile_to_charge->toArray());
        $profiles = $order->collectProfiles();

        //dsm( $payment_method->get('card_number'));

        $addres = $payment_method->getBillingProfile()->address->first();
        //dsm($payment_method->getBillingProfile()->address->first());


        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($this->configuration['api_login']);
        $merchantAuthentication->setTransactionKey($this->configuration['transaction_key']);

        $OpaqueData = new AnetAPI\OpaqueDataType();
        $OpaqueData->setDataDescriptor($payment_details['data_descriptor']);
        $OpaqueData->setDataValue($payment_details['data_value']);

        $paymentType = new AnetAPI\PaymentType();
        $paymentType->setOpaqueData($OpaqueData);

        // Set the transaction's refId
        $refId = $order->id();

        $authOrder = new AnetAPI\OrderType();
        $authOrder->setInvoiceNumber($payment->getOrderId());
        $authOrder->setDescription("{$title} subscription");

        // Subscription Type Info
        $subscription = new AnetAPI\ARBSubscriptionType();
        $subscription->setName($title);
        $subscription->setPayment($paymentType);
        $subscription->setOrder($authOrder);

        $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
        $interval->setLength($occurrency);
        $interval->setUnit($unit);

        $paymentSchedule = new AnetAPI\PaymentScheduleType();
        $paymentSchedule->setInterval($interval);
        $paymentSchedule->setStartDate(new \DateTime());
        $paymentSchedule->setTotalOccurrences(9999);
        $paymentSchedule->setTrialOccurrences("0");

        $subscription->setPaymentSchedule($paymentSchedule);
        $subscription->setAmount($payment->getAmount()->getNumber());
        $subscription->setTrialAmount("0.00");

        $billTo = new AnetAPI\NameAndAddressType();
        $billTo->setFirstName($addres->given_name);
        $billTo->setLastName($addres->family_name);
        $billTo->setCompany($addres->organization);
        $billTo->setAddress($addres->address_line1);
        $billTo->setCity($addres->locality);
        $billTo->setState($addres->administrative_area);
        $billTo->setZip($addres->postal_code);
        $countries = CountryManager::getStandardList();

        $fullCountryName = $countries[$addres->country_code]->__toString();
        $billTo->setCountry($fullCountryName);

        $subscription->setBillTo($billTo);

        $currentUser = \Drupal::currentUser();
        $userMail = $currentUser->getEmail();

        $customerProfile = new AnetAPI\CustomerType();
        $customerProfile->setId($currentUser->id());
        $customerProfile->setEmail($userMail);

        $subscription->setCustomer($customerProfile);

        $request = new AnetAPI\ARBCreateSubscriptionRequest();
        $request->setmerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setSubscription($subscription);

        $controller = new AnetController\ARBCreateSubscriptionController($request);

        $response = null;                
        if ($this->configuration['mode'] === 'test') {
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else{
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
            //  echo "SUCCESS: Subscription ID : " . $response->getSubscriptionId() . "\n";
            //  throw new PaymentGatewayException('OK');
            \Drupal::logger('authorize_subscription')->notice('Created subscription order: @order_id, user @uid subscription id: @subs_id response: @response',
                    [
                        '@order_id' => $order->id(),
                        '@uid' => $currentUser->id(),
                        '@subs_id' => $response->getSubscriptionId(),
                        '@response' => json_encode($response)
            ]);
            $payment->setRemoteId($response->getSubscriptionId());
            $payment->save();
        } else {

            $error = $response->getMessages()->getMessage();
            $errorMessages .= "ERROR :  Invalid response\n";
            $errorMessages .= "Response : " . $error[0]->getCode() . "  " . $error[0]->getText() . json_encode($this->configuration) . "\n";
            throw new PaymentGatewayException($errorMessages);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     *
     * @todo Needs kernel test
     */
    public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {

        if (isset($payment_details['data_value']) && $payment_details['data_descriptor']) {
            $tempstore = \Drupal::service('tempstore.private')->get('authorize_subscription');
            $tempstore->set('data_descriptor', $payment_details['data_descriptor']);
            $tempstore->set('data_value', $payment_details['data_value']);
        }

        // We don't want 3DS on the user payment method form.
        if (!empty($this->getConfiguration()['cca_status']) && !empty($payment_details['cca_jwt_token'])) {
            if (empty($payment_details['cca_jwt_response_token'])) {
                throw new PaymentGatewayException('Cannot continue when CCA is enabled but not used.');
            }

            $configuration = Configuration::forSymmetricSigner(
                            new Sha256(),
                            InMemory::plainText($this->getCcaApiKey())
            );
            // Set validation constraints.
            $constraints = [
                new SignedWith($configuration->signer(), $configuration->signingKey()),
            ];
            $configuration->setValidationConstraints(...$constraints);

            $token = $configuration->parser()
                    ->parse($payment_details['cca_jwt_response_token']);

            if (!$configuration->validator()
                            ->validate($token, ...$configuration->validationConstraints())) {
                throw new PaymentGatewayException('Response CCA JWT is not valid.');
            }
            $payload = $token->claims()->get('Payload');
            if (isset($payload->getValue()->Payment->ExtendedData->SignatureVerification) && $payload->getValue()->Payment->ExtendedData->SignatureVerification === 'N') {
                throw new PaymentGatewayException('Unsuccessful signature verification.');
            }
        }

        $required_keys = [
            'data_descriptor', 'data_value',
        ];
        foreach ($required_keys as $required_key) {
            if (empty($payment_details[$required_key])) {
                throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
            }
        }

        if (!empty($this->getConfiguration()['cca_status']) && !empty($payment_details['cca_jwt_token'])) {
            $value = [];
            if (isset($payload->getValue()->Payment->ExtendedData->CAVV)) {
                $value['cavv'] = $payload->getValue()->Payment->ExtendedData->CAVV;
                $this->privateTempStore->get('commerce_authnet')->set($payment_method->id(), $value);
            }
            if (isset($payload->getValue()->Payment->ExtendedData->ECIFlag)) {
                $value['eci'] = $payload->getValue()->Payment->ExtendedData->ECIFlag;
                $this->privateTempStore->get('commerce_authnet')->set($payment_method->id(), $value);
            }
        }
    }

    public function createPayment1(PaymentInterface $payment, $capture = TRUE) {
        $this->assertPaymentState($payment, ['new']);
        $payment_method = $payment->getPaymentMethod();
        $this->assertPaymentMethod($payment_method);

        //    $this->createSubscription(23);
        //    return;
        $order = $payment->getOrder();
        $owner = $payment_method->getOwner();

        // Transaction request.
        $transaction_request = new TransactionRequest([
            'transactionType' => ($capture) ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
            'amount' => $payment->getAmount()->getNumber(),
        ]);

        dsm('trans');
        dsm($transaction_request);

        $tempstore_3ds = $this->privateTempStore->get('commerce_authnet')->get($payment_method->id());
        if (!empty($tempstore_3ds)) {
            // Do not send ECI and CAVV values when reusing a payment method.
            $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
            $payment_method_has_been_used = $payment_storage->getQuery()
                    ->condition('payment_method', $payment_method->id())
                    ->range(0, 1)
                    ->execute();
            if (!$payment_method_has_been_used) {
                $cardholder_authentication = new CardholderAuthentication();
                $cardholder_authentication_empty = TRUE;
                if (!empty($tempstore_3ds['eci']) && $tempstore_3ds['eci'] != '07') {
                    $cardholder_authentication->authenticationIndicator = $tempstore_3ds['eci'];
                    $cardholder_authentication_empty = FALSE;
                }
                if (!empty($tempstore_3ds['cavv'])) {
                    // This is quite undocumented, but seems that cavv needs to be
                    // urlencoded.
                    // @see https://community.developer.authorize.net/t5/Integration-and-Testing/Cardholder-Authentication-extraOptions-invalid-error/td-p/57955
                    $cardholder_authentication->cardholderAuthenticationValue = urlencode($tempstore_3ds['cavv']);
                    $cardholder_authentication_empty = FALSE;
                }
                if (!$cardholder_authentication_empty) {
                    $transaction_request->addDataType($cardholder_authentication);
                }
            } else {
                $this->privateTempStore->get('commerce_authnet')->delete($payment_method->id());
            }
        }

        // @todo update SDK to support data type like this.
        // Initializing the profile to charge and adding it to the transaction.
        $customer_profile_id = $this->getRemoteCustomerId($owner);
//        dsm('cust_prof');
//        dsm($customer_profile_id);
        if (empty($customer_profile_id)) {
            $customer_profile_id = $this->getPaymentMethodCustomerId($payment_method);
        }
        $payment_profile_id = $this->getRemoteProfileId($payment_method);
        $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
        $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
        $transaction_request->addData('profile', $profile_to_charge->toArray());
        $profiles = $order->collectProfiles();
        if (isset($profiles['shipping']) && !$profiles['shipping']->get('address')->isEmpty()) {
            /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
            $shipping_address = $profiles['shipping']->get('address')->first();
            $ship_data = [
                // @todo how to allow customizing this.
                'firstName' => $shipping_address->getGivenName(),
                'lastName' => $shipping_address->getFamilyName(),
                'address' => substr($shipping_address->getAddressLine1() . ' ' . $shipping_address->getAddressLine2(), 0, 60),
                'country' => $shipping_address->getCountryCode(),
                'company' => $shipping_address->getOrganization(),
                'city' => $shipping_address->getLocality(),
                'state' => $shipping_address->getAdministrativeArea(),
                'zip' => $shipping_address->getPostalCode(),
            ];
            $transaction_request->addDataType(new ShipTo(array_filter($ship_data)));
        }

        // Adding order information to the transaction.
        $transaction_request->addOrder(new OrderDataType([
                    'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
        ]));
        $transaction_request->addData('customerIP', $order->getIpAddress());

        // Adding line items.
        $line_items = $this->getLineItems($order);
        foreach ($line_items as $line_item) {
            $transaction_request->addLineItem($line_item);
        }

        // Adding tax information to the transaction.
        $transaction_request->addData('tax', $this->getTax($order)->toArray());
        $transaction_request->addData('shipping', $this->getShipping($order)->toArray());

//        dsm('auth_conf');
//        dsm($this->authnetConfiguration);

        $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
        $request->setTransactionRequest($transaction_request);

//        dsm('request');
//        dsm($request);

        $response = $request->execute();

        if ($response->getResultCode() !== 'Ok') {
            $this->logResponse($response);
            $message = $response->getMessages()[0];
            switch ($message->getCode()) {
                case 'E00040':
                    $payment_method->delete();
                    throw new PaymentGatewayException('The provided payment method is no longer valid');

                case 'E00042':
                    $payment_method->delete();
                    throw new PaymentGatewayException('You cannot add more than 10 payment methods.');

                default:
                    throw new PaymentGatewayException($message->getText());
            }
        }

        if (!empty($response->getErrors())) {
            $message = $response->getErrors()[0];
            throw new HardDeclineException($message->getText());
        }

        // Select the next state based on fraud detection results.
        $code = $response->getMessageCode();
        $expires = 0;
        $next_state = 'authorization';
        if ($code == 1 && $capture) {
            $next_state = 'completed';
        }
        // Do not authorize, but hold for review.
        elseif ($code == 252) {
            $next_state = 'unauthorized_review';
            $expires = strtotime('+5 days');
        }
        // Authorized, but hold for review.
        elseif ($code == 253) {
            $next_state = 'authorization_review';
            $expires = strtotime('+5 days');
        }
        $payment->setExpiresTime($expires);
        $payment->setState($next_state);
        $payment->setRemoteId($response->transactionResponse->transId);
        $payment->setAvsResponseCode($response->transactionResponse->avsResultCode);
        // @todo Find out how long an authorization is valid, set its expiration.
        $payment->save();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsReusableOption(): bool {
        return TRUE;
    }

}
