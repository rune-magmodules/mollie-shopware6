<?php declare(strict_types=1);

namespace Kiener\MolliePayments\Handler;

use Kiener\MolliePayments\Exception\PaymentUrlException;
use Kiener\MolliePayments\Facade\MolliePaymentDoPay;
use Kiener\MolliePayments\Facade\MolliePaymentFinalize;
use Kiener\MolliePayments\Service\Transition\TransactionTransitionServiceInterface;
use Kiener\MolliePayments\Struct\MollieOrderCustomFieldsStruct;
use Mollie\Api\Exceptions\ApiException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class PaymentHandler implements AsynchronousPaymentHandlerInterface
{
    protected const FIELD_ORDER_NUMBER = 'orderNumber';
    protected const FIELD_BILLING_ADDRESS = 'billingAddress';
    protected const FIELD_BILLING_EMAIL = 'billingEmail';

    /** @var string */
    protected $paymentMethod;

    /** @var array */
    protected $paymentMethodData = [];

    /** @var LoggerInterface */
    protected $logger;

    /** @var MolliePaymentDoPay */
    private $payFacade;

    /** @var TransactionTransitionServiceInterface */
    private $transactionTransitionService;

    /** @var MolliePaymentFinalize */
    private $finalizeFacade;


    /**
     * @param LoggerInterface $logger
     * @param MolliePaymentDoPay $payFacade
     * @param MolliePaymentFinalize $finalizeFacade
     * @param TransactionTransitionServiceInterface $transactionTransitionService
     */
    public function __construct(LoggerInterface $logger, MolliePaymentDoPay $payFacade, MolliePaymentFinalize $finalizeFacade, TransactionTransitionServiceInterface $transactionTransitionService)
    {
        $this->logger = $logger;
        $this->payFacade = $payFacade;
        $this->transactionTransitionService = $transactionTransitionService;
        $this->finalizeFacade = $finalizeFacade;
    }

    /**
     * @param array $orderData
     * @param OrderEntity $orderEntity
     * @param SalesChannelContext $salesChannelContext
     * @param CustomerEntity $customer
     * @return array
     */
    public function processPaymentMethodSpecificParameters(array $orderData, OrderEntity $orderEntity, SalesChannelContext $salesChannelContext, CustomerEntity $customer): array
    {
        return $orderData;
    }

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse @see AsyncPaymentProcessException exception if an error ocurres while processing the
     *                          payment
     * @throws ApiException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {

        $this->logger->info(
            'Starting Checkout for order ' . $transaction->getOrder()->getOrderNumber() . ' with payment: ' . $this->paymentMethod,
            [
                'saleschannel' => $salesChannelContext->getSalesChannel()->getName(),
                'cart' => [
                    'amount' => $transaction->getOrder()->getAmountTotal(),
                ],
            ]
        );


        try {

            $paymentData = $this->payFacade->preparePayProcessAtMollie(
                $this->paymentMethod,
                $transaction,
                $salesChannelContext,
                $this
            );

            $paymentUrl = $paymentData->getCheckoutURL();

        } catch (Throwable $exception) {

            $this->logger->error(
                'Error when starting Mollie payment: ' . $exception->getMessage(),
                [
                    'function' => 'order-prepare',
                ]
            );

            throw new PaymentUrlException($transaction->getOrderTransaction()->getId(), $exception->getMessage());
        }

        try {

            $this->transactionTransitionService->processTransaction($transaction->getOrderTransaction(), $salesChannelContext->getContext());

        } catch (\Exception $exception) {

            $this->logger->warning(
                sprintf('Could not set payment to in progress. Got error %s', $exception->getMessage())
            );
        }

        /**
         * Redirect the customer to the payment URL. Afterwards the
         * customer is redirected back to Shopware's finish page, which
         * leads to the @finalize function.
         */
        return new RedirectResponse($paymentUrl);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $orderAttributes = new MollieOrderCustomFieldsStruct($transaction->getOrder()->getCustomFields());
        $molliedID = $orderAttributes->getMollieOrderId();

        $this->logger->info(
            'Finalizing Mollie payment for order ' . $transaction->getOrder()->getOrderNumber() . ' with payment: ' . $this->paymentMethod . ' and Mollie ID' . $molliedID,

            [
                'saleschannel' => $salesChannelContext->getSalesChannel()->getName(),
            ]
        );

        try {

            $this->finalizeFacade->finalize($transaction, $salesChannelContext);

        } catch (AsyncPaymentFinalizeException|CustomerCanceledAsyncPaymentException $ex) {

            $this->logger->error(
                'Error when finalizing order ' . $transaction->getOrder()->getOrderNumber() . ', Mollie ID: ' . $molliedID . ', ' . $ex->getMessage()
            );

            # these are already correct exceptions
            # that cancel the Shopware order in a coordinated way by Shopware
            throw $ex;

        } catch (Throwable $ex) {

            # this processes all unhandled exceptions.
            # we need to log whatever happens in here, and then also
            # throw an exception that breaks the order in a coordinated way.
            # Only the 2 exceptions above, lead to a correct failure-behaviour in Shopware.
            # All other exceptions would lead to a 500 exception in the storefront.
            $this->logger->error(
                'Unknown Error when finalizing order ' . $transaction->getOrder()->getOrderNumber() . ', Mollie ID: ' . $molliedID . ', ' . $ex->getMessage()
            );

            throw new AsyncPaymentFinalizeException(
                $transaction->getOrderTransaction()->getId(),
                'An unknown error happened when finalizing the order. Please see the Shopware logs for more. It can be that the payment in Mollie was succesful and the Shopware order is now cancelled or failed!'
            );
        }
    }
}
