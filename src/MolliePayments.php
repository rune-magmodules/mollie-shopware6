<?php declare(strict_types=1);

namespace Kiener\MolliePayments;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Kiener\MolliePayments\Compatibility\DependencyLoader;
use Kiener\MolliePayments\Service\ApplePayDirect\ApplePayDomainVerificationService;
use Kiener\MolliePayments\Service\CustomFieldService;
use Kiener\MolliePayments\Service\PaymentMethodService;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Kiener\MolliePayments\Service\Subscription\CustomFieldService as SubscriptionsCustomFieldService;

class MolliePayments extends Plugin
{

    const PLUGIN_VERSION = '1.5.5';

    /**
     * @param ContainerBuilder $container
     * @throws Exception
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->container = $container;

        # load the dependencies that are compatible
        # with our current shopware version
        $loader = new DependencyLoader($container);
        $loader->loadServices();
    }

    public function boot(): void
    {
        parent::boot();
    }

    public function install(InstallContext $context): void
    {
        parent::install($context);

        /** @var EntityRepositoryInterface $customFieldRepository */
        $customFieldRepository = $this->container->get('custom_field_set.repository');

        // Add custom fields
        $customFieldService = new CustomFieldService(
            $this->container,
            $customFieldRepository
        );

        $customFieldService->addCustomFields($context->getContext());

        $this->addReminderEmailTemplate($context);
    }

    public function update(UpdateContext $context): void
    {
        parent::update($context);

        if ($context->getPlugin()->isActive() === true) {
            // add domain verification
            /** @var ApplePayDomainVerificationService $domainVerificationService */
            $domainVerificationService = $this->container->get(ApplePayDomainVerificationService::class);
            $domainVerificationService->downloadDomainAssociationFile();
        }
    }

    public function postInstall(InstallContext $context): void
    {
        parent::postInstall($context);
    }

    public function uninstall(UninstallContext $context): void
    {
        parent::uninstall($context);

        $this->deleteReminderEmailTemplate($context);

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        try {
            if (method_exists($connection, 'executeStatement')) {
                $connection->executeStatement('DROP TABLE IF EXISTS `mollie_subscription_to_product`');
            } else {
                $connection->exec('DROP TABLE IF EXISTS `mollie_subscription_to_product`');
            }
        } catch (Exception $exception) {
        }
    }

    public function activate(ActivateContext $context): void
    {
        parent::activate($context);

        /** @var PaymentMethodService $paymentMethodHelper */
        $paymentMethodHelper = $this->container->get('Kiener\MolliePayments\Service\PaymentMethodService');

        // Add payment methods
        $paymentMethodHelper
            ->setClassName(get_class($this))
            ->addPaymentMethods($context->getContext());

        // add domain verification
        /** @var ApplePayDomainVerificationService $domainVerificationService */
        $domainVerificationService = $this->container->get(ApplePayDomainVerificationService::class);
        $domainVerificationService->downloadDomainAssociationFile();
    }

    public function deactivate(DeactivateContext $context): void
    {
        parent::deactivate($context);
    }

    /**
     * @param $context
     */
    private function addReminderEmailTemplate($context)
    {
        /** @var EntityRepositoryInterface $mailTemplateTypeRepository */
        $mailTemplateTypeRepository = $this->container->get('mail_template_type.repository');
        /** @var EntityRepositoryInterface $mailTemplateRepository */
        $mailTemplateRepository = $this->container->get('mail_template.repository');
        $mailTemplateTypeId = Uuid::randomHex();
        $mailTemplateType = [
            [
                'id' => $mailTemplateTypeId,
                'name' => 'Molle Reminder',
                'technicalName' => 'mollie_subscriptions_reminder',
                'availableEntities' => [
                    'subscription' => 'mollie_subscription_to_product',
                    'salesChannel' => 'sales_channel'
                ]
            ]
        ];
        $mailTemplate = [
            [
                'id' => Uuid::randomHex(),
                'mailTemplateTypeId' => $mailTemplateTypeId,
                'subject' => [
                    'en-GB' => 'Upcoming subscription renewal from {{ salesChannel.name }}',
                    'de-DE' => 'Anstehende Abonnementverlängerung von {{ salesChannel.name }}'
                ],
                'contentPlain' => "Dear {% if subscriptions.customer.salutation %}
                {{ subscriptions.customer.salutation ~ ' ' }}
                {% endif %}{{ subscriptions.customer.name }}\n
                Good news! We are getting your {{ subscriptions.productName }} subscription ready for 
                {{ subscriptions.nextPaymentDate }}, 
this e-mail is just to inform you that the payment with an amount of {{ subscriptions.amount }} 
is going to be captured on this date as well. 
For any changes, you can log in to your account on {{ salesChannel.name }} and pause or cancel 
the subscription at any time.
\nThanks again\n{{ salesChannel.translated.name }}",
                'contentHtml' => '<div style="font-family:arial; font-size:12px;"><br/>
    <p>Dear {% if subscriptions.customer.salutation %}{{ subscriptions.customer.salutation ~ " " }}
    {% endif %}{{ subscriptions.customer.name }},</p>
    <p>Good news! We are getting your {{ subscriptions.productName }} subscription ready for 
    {{ subscriptions.nextPaymentDate }}, 
this e-mail is just to inform you that the payment with an amount of {{ subscriptions.amount }} is going to be 
captured on this date as well. 
For any changes, you can log in to your account on {{ salesChannel.name }} and pause or cancel the subscription at any 
time.</p>
<p>Thanks again</p>
<p>{{ salesChannel.translated.name }}</p>
</div>',
            ]
        ];

        try {
            $mailTemplateTypeRepository->create($mailTemplateType, $context->getContext());
            $mailTemplateRepository->create($mailTemplate, $context->getContext());
        } catch (UniqueConstraintViolationException $exception) {
        }
    }

    /**
     * @param $context
     */
    private function deleteReminderEmailTemplate($context)
    {
        /** @var EntityRepositoryInterface $mailTemplateTypeRepository */
        $mailTemplateTypeRepository = $this->container->get('mail_template_type.repository');

        /** @var EntityRepositoryInterface $mailTemplateRepository */
        $mailTemplateRepository = $this->container->get('mail_template.repository');

        /** @var MailTemplateTypeEntity $reminderEmailTemplate */
        $reminderEmailTemplate = $mailTemplateTypeRepository->search(
            (new Criteria())
                ->addFilter(new EqualsFilter('technicalName', 'mollie_subscriptions_reminder')),
            $context->getContext()
        )->first();

        $mailTemplateIds = $mailTemplateRepository->searchIds(
            (new Criteria())
                ->addFilter(new EqualsFilter('mailTemplateTypeId', $reminderEmailTemplate->getId())),
            $context->getContext()
        )->getIds();

        $ids = array_map(static function ($id) {
            return ['id' => $id];
        }, $mailTemplateIds);

        $mailTemplateRepository->delete($ids, $context->getContext());

        $mailTemplateTypeRepository->delete([
            ['id' => $reminderEmailTemplate->getId()]
        ], $context->getContext());
    }
}
