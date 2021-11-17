<?php declare(strict_types=1);

namespace Kiener\MolliePayments\Storefront\Controller;

use Kiener\MolliePayments\Page\Account\Mollie\AccountSubscriptionsPageLoader;
use Kiener\MolliePayments\Service\LoggerService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\LoginRequired;
use Kiener\MolliePayments\Factory\MollieApiFactory;
use Kiener\MolliePayments\Service\Subscription\CancelSubscriptionsService;

use Kiener\MolliePayments\Service\Subscription\EmailService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AccountController extends StorefrontController
{
    /**
     * @var AccountSubscriptionsPageLoader
     */
    private AccountSubscriptionsPageLoader $subscriptionsPageLoader;

    /**
     * @var CancelSubscriptionsService
     */
    private CancelSubscriptionsService $cancelSubscriptionsService;

    private EmailService $emailService;
    private EntityRepositoryInterface $mollieSubscriptionsRepository;

    /**
     * @param AccountSubscriptionsPageLoader $subscriptionsPageLoader
     * @param CancelSubscriptionsService $cancelSubscriptionsService
     */
    public function __construct(
        AccountSubscriptionsPageLoader $subscriptionsPageLoader,
        CancelSubscriptionsService $cancelSubscriptionsService,
        EmailService $emailService,
        EntityRepositoryInterface $mollieSubscriptionsRepository
    ) {
        $this->subscriptionsPageLoader = $subscriptionsPageLoader;
        $this->cancelSubscriptionsService = $cancelSubscriptionsService;
        $this->emailService = $emailService;
        $this->mollieSubscriptionsRepository = $mollieSubscriptionsRepository;
    }

    /**
     * @LoginRequired()
     * @Route("/account/mollie", name="frontend.account.mollie.page", options={"seo"="false"},
     *     methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function mollieOverview(
        Request $request,
        SalesChannelContext $salesChannelContext
    ): Response {
        $page = $this->subscriptionsPageLoader->load($request, $salesChannelContext);
        return $this->renderStorefront(
            '@Storefront/storefront/page/account/mollie-history/index.html.twig',
            ['page' => $page]
        );
    }

    /**
     * @Route("/account/mollie/cancel/{subscriptionId}/{mollieCustomerId}/{salesChannelId}",
     *     name="frontend.account.mollie.cancel",
     *     methods={"POST"})
     */
    public function cancelSubscriptions($subscriptionId, $mollieCustomerId, $salesChannelId): Response
    {
        if ($this->cancelSubscriptionsService->cancelSubscriptions($subscriptionId, $mollieCustomerId, $salesChannelId)) {
            $this->addFlash(self::SUCCESS, $this->trans('account.cancelSubscription', ['%1%' => $subscriptionId]));
        }

        return $this->redirectToRoute('frontend.account.mollie.page');
    }
}
