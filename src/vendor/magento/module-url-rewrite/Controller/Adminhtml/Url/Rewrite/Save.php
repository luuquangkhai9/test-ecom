<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\UrlRewrite\Controller\Adminhtml\Url\Rewrite;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

class Save extends \Magento\UrlRewrite\Controller\Adminhtml\Url\Rewrite implements HttpPostActionInterface
{
    /**
     * @var \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator
     */
    protected $productUrlPathGenerator;

    /**
     * @var \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator
     */
    protected $categoryUrlPathGenerator;

    /**
     * @var \Magento\CmsUrlRewrite\Model\CmsPageUrlPathGenerator
     */
    protected $cmsPageUrlPathGenerator;

    /**
     * @var \Magento\UrlRewrite\Model\UrlFinderInterface
     */
    protected $urlFinder;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $productUrlPathGenerator
     * @param \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param \Magento\CmsUrlRewrite\Model\CmsPageUrlPathGenerator $cmsPageUrlPathGenerator
     * @param UrlFinderInterface $urlFinder
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $productUrlPathGenerator,
        \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator $categoryUrlPathGenerator,
        \Magento\CmsUrlRewrite\Model\CmsPageUrlPathGenerator $cmsPageUrlPathGenerator,
        UrlFinderInterface $urlFinder
    ) {
        parent::__construct($context);
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->cmsPageUrlPathGenerator = $cmsPageUrlPathGenerator;
        $this->urlFinder = $urlFinder;
    }

    /**
     * Override urlrewrite data, basing on current category and product
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $model
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _handleCatalogUrlRewrite($model)
    {
        $productId = $this->_getProduct()->getId();
        $categoryId = $this->_getCategory()->getId();
        if ($productId || $categoryId) {
            if ($model->isObjectNew()) {
                $model->setEntityType($productId ? self::ENTITY_TYPE_PRODUCT : self::ENTITY_TYPE_CATEGORY)
                    ->setEntityId($productId ?: $categoryId);
                if ($productId && $categoryId) {
                    $model->setMetadata(['category_id' => $categoryId]);
                }
            }
            $model->setTargetPath($this->getTargetPath($model));
        }
    }

    /**
     * Get Target Path
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $model
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getTargetPath($model)
    {
        $targetPath = $this->getCanonicalTargetPath();
        if ($model->getRedirectType() && !$model->getIsAutogenerated()) {
            $data = [
                UrlRewrite::ENTITY_ID => $model->getEntityId(),
                UrlRewrite::TARGET_PATH => $targetPath,
                UrlRewrite::ENTITY_TYPE => $model->getEntityType(),
                UrlRewrite::STORE_ID => $model->getStoreId(),
            ];
            $rewrite = $this->urlFinder->findOneByData($data);
            if (!$rewrite) {
                $model->getEntityType() === self::ENTITY_TYPE_PRODUCT ? $this->checkProductCorrelation($model) :
                    $this->checkCategoryCorrelation($model);
            } else {
                $targetPath = $rewrite->getRequestPath();
            }
        }
        return $targetPath;
    }

    /**
     * Checks if rewrite details match category properties
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $model
     * @return void
     * @throws LocalizedException
     */
    private function checkCategoryCorrelation(\Magento\UrlRewrite\Model\UrlRewrite $model): void
    {
        if (false === in_array($model->getStoreId(), $this->_getCategory()->getStoreIds())) {
            throw new LocalizedException(
                __("The selected category isn't associated with the selected store.")
            );
        }
    }

    /**
     * Checks if rewrite details match product properties
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $model
     * @return void
     * @throws LocalizedException
     */
    private function checkProductCorrelation(\Magento\UrlRewrite\Model\UrlRewrite $model): void
    {
        if (false === ($this->_getProduct()->canBeShowInCategory($this->_getCategory()->getId())) &&
            in_array($model->getStoreId(), $this->_getProduct()->getStoreIds())) {
            throw new LocalizedException(
                __("The selected product isn't associated with the selected store or category.")
            );
        }
    }

    /**
     * Get rewrite canonical target path
     *
     * @return string
     */
    protected function getCanonicalTargetPath()
    {
        $product = $this->_getProduct()->getId() ? $this->_getProduct() : null;
        $category = $this->_getCategory()->getId() ? $this->_getCategory() : null;
        return $product
            ? $this->productUrlPathGenerator->getCanonicalUrlPath($product, $category)
            : $this->categoryUrlPathGenerator->getCanonicalUrlPath($category);
    }

    /**
     * Override URL rewrite data, basing on current CMS page
     *
     * @param \Magento\UrlRewrite\Model\UrlRewrite $model
     * @return void
     */
    private function _handleCmsPageUrlRewrite($model)
    {
        $cmsPage = $this->_getCmsPage();
        if ($cmsPage->getId()) {
            if ($model->isObjectNew()) {
                $model->setEntityType(self::ENTITY_TYPE_CMS_PAGE)->setEntityId($cmsPage->getId());
            }
            if ($model->getRedirectType() && !$model->getIsAutogenerated()) {
                $targetPath = $this->cmsPageUrlPathGenerator->getUrlPath($cmsPage);
            } else {
                $targetPath = $this->cmsPageUrlPathGenerator->getCanonicalUrlPath($cmsPage);
            }
            $model->setTargetPath($targetPath);
        }
    }

    /**
     * Process save URL rewrite request
     *
     * @return void
     */
    public function execute()
    {
        $data = $this->getRequest()->getPostValue();
        if ($data) {
            /** @var $session \Magento\Backend\Model\Session */
            $session = $this->_objectManager->get(\Magento\Backend\Model\Session::class);
            try {
                $model = $this->_getUrlRewrite();

                $requestPath = $this->getRequest()->getParam('request_path');
                $this->_objectManager->get(
                    \Magento\UrlRewrite\Helper\UrlRewrite::class
                )->validateRequestPath($requestPath);

                $model->setEntityType($this->getRequest()->getParam('entity_type') ?: self::ENTITY_TYPE_CUSTOM)
                    ->setRequestPath($requestPath)
                    ->setTargetPath($this->getRequest()->getParam('target_path', $model->getTargetPath()))
                    ->setRedirectType($this->getRequest()->getParam('redirect_type'))
                    ->setStoreId($this->getRequest()->getParam('store_id', 0))
                    ->setDescription($this->getRequest()->getParam('description'));

                $this->_handleCatalogUrlRewrite($model);
                $this->_handleCmsPageUrlRewrite($model);
                $model->save();

                $this->messageManager->addSuccess(__('The URL Rewrite has been saved.'));
                $this->_redirect('adminhtml/*/');
                return;
            } catch (LocalizedException $e) {
                $this->messageManager->addError($e->getMessage());
                $session->setUrlRewriteData($data);
            } catch (\Exception $e) {
                $this->messageManager->addException(
                    $e,
                    __('An error occurred while saving the URL rewrite. Please try to save again.')
                );
                $session->setUrlRewriteData($data);
            }
        }
        $this->getResponse()->setRedirect($this->_redirect->getRedirectUrl($this->getUrl('*')));
    }
}