<?php declare(strict_types=1);

namespace Kiener\MolliePayments\Page\Account\Mollie;

use Kiener\MolliePayments\Service\CustomerService;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\Order\Exception\GuestNotAuthenticatedException;
use Shopware\Core\Checkout\Order\Exception\WrongGuestCredentialsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Page\StorefrontSearchResult;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class AccountSubscriptionsPageLoader
{
    /**
     * @var EntityRepositoryInterface
     */
    private EntityRepositoryInterface $mollieSubscriptionsRepository;

    /**
     * @var GenericPageLoaderInterface
     */
    private GenericPageLoaderInterface $genericLoader;

    /**
     * @var CustomerService
     */
    private CustomerService $customerService;

    /**
     * @param EntityRepositoryInterface $mollieSubscriptionsRepository
     * @param GenericPageLoaderInterface $genericLoader
     * @param CustomerService $customerService
     */
    public function __construct(
        EntityRepositoryInterface $mollieSubscriptionsRepository,
        GenericPageLoaderInterface $genericLoader,
        CustomerService $customerService
    ) {
        $this->mollieSubscriptionsRepository = $mollieSubscriptionsRepository;
        $this->genericLoader = $genericLoader;
        $this->customerService = $customerService;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return AccountSubscriptionsPage
     */
    public function load(Request $request, SalesChannelContext $salesChannelContext): AccountSubscriptionsPage
    {
        if (!$salesChannelContext->getCustomer() && $request->get('deepLinkCode', false) === false) {
            throw new CustomerNotLoggedInException();
        }

        $page = $this->genericLoader->load($request, $salesChannelContext);

        $page = AccountSubscriptionsPage::createFrom($page);

        if ($page->getMetaInformation()) {
            $page->getMetaInformation()->setRobots('noindex,follow');
        }

        $subscriptions = $this->getSubscriptions($request, $salesChannelContext);

        $page->setSubscriptions(StorefrontSearchResult::createFrom($subscriptions));

        $page->setDeepLinkCode($request->get('deepLinkCode'));

        $page->setTotal($subscriptions->getTotal());

        return $page;
    }

    /**
     * @throws CustomerNotLoggedInException
     * @throws GuestNotAuthenticatedException
     * @throws WrongGuestCredentialsException
     */
    private function getSubscriptions(Request $request, SalesChannelContext $context): EntitySearchResult
    {
        $customerId = $this->customerService->getMollieCustomerId(
            $context->getCustomer()->getId(),
            $context->getSalesChannelId(),
            $context->getContext()
        );

        $criteria = $this->createCriteria($request, $customerId);

        /** @var EntitySearchResult $subscriptions */
        return $this->mollieSubscriptionsRepository->search($criteria, $context->getContext());
    }

    /**
     * @param Request $request
     * @param string|null $customerId
     * @return Criteria
     */
    private function createCriteria(Request $request, string $customerId = null): Criteria
    {
        $limit = $request->get('limit');
        $limit = $limit ? (int) $limit : 10;
        $page = $request->get('p');
        $page = $page ? (int) $page : 1;

        $criteria = (new Criteria())
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->setLimit($limit)
            ->setOffset(($page - 1) * $limit)
            ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        if (!is_null($customerId)) {
            $criteria->addFilter(new EqualsFilter('mollieCustomerId', $customerId));
        }

        if ($request->get('deepLinkCode')) {
            $criteria->addFilter(new EqualsFilter('deepLinkCode', $request->get('deepLinkCode')));
        }

        return $criteria;
    }
}
