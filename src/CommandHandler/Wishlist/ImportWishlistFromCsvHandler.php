<?php

/*
 * This file was created by developers working at BitBag
 * Do you need more information about us and what we do? Visit our https://bitbag.io website!
 * We are hiring developers from all over the world. Join us and start your new, exciting adventure and become part of us: https://bitbag.io/career
*/

declare(strict_types=1);

namespace BitBag\SyliusWishlistPlugin\CommandHandler\Wishlist;

use BitBag\SyliusWishlistPlugin\Command\Wishlist\ImportWishlistFromCsv;
use BitBag\SyliusWishlistPlugin\Controller\Action\AddProductVariantToWishlistAction;
use BitBag\SyliusWishlistPlugin\Factory\CsvSerializerFactoryInterface;
use BitBag\SyliusWishlistPlugin\Model\DTO\CsvWishlistProduct;
use BitBag\SyliusWishlistPlugin\Model\DTO\CsvWishlistProductInterface;
use Gedmo\Exception\UploadableInvalidMimeTypeException;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ImportWishlistFromCsvHandler implements MessageHandlerInterface
{
    private AddProductVariantToWishlistAction $addProductVariantToWishlistAction;

    private ProductVariantRepositoryInterface $productVariantRepository;

    private array $allowedMimeTypes;

    private CsvSerializerFactoryInterface $csvSerializerFactory;

    private FlashBagInterface $flashBag;

    private TranslatorInterface $translator;

    public function __construct(
        AddProductVariantToWishlistAction $addProductVariantToWishlistAction,
        ProductVariantRepositoryInterface $productVariantRepository,
        array $allowedMimeTypes,
        CsvSerializerFactoryInterface $csvSerializerFactory,
        FlashBagInterface $flashBag,
        TranslatorInterface $translator
    ) {
        $this->addProductVariantToWishlistAction = $addProductVariantToWishlistAction;
        $this->productVariantRepository = $productVariantRepository;
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->csvSerializerFactory = $csvSerializerFactory;
        $this->flashBag = $flashBag;
        $this->translator = $translator;
    }

    public function __invoke(ImportWishlistFromCsv $importWishlistFromCsv): Response
    {
        $fileInfo = $importWishlistFromCsv->getFileInfo();
        $request = $importWishlistFromCsv->getRequest();
        $wishlistId = $importWishlistFromCsv->getWishlistId();

        $this->getDataFromFile($fileInfo, $request);

        return $this->addProductVariantToWishlistAction->__invoke($wishlistId, $request);
    }

    private function getDataFromFile(\SplFileInfo $fileInfo, Request $request): void
    {
        if (!$this->fileIsValidMimeType($fileInfo)) {
            throw new UploadableInvalidMimeTypeException();
        }

        $csvData = file_get_contents((string) $fileInfo);

        $csvWishlistProducts = $this->csvSerializerFactory->createNew()->deserialize($csvData, sprintf('%s[]', CsvWishlistProduct::class), 'csv', [
            AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true,
            CsvEncoder::AS_COLLECTION_KEY => true,
        ]);

        /** @var CsvWishlistProduct $csvWishlistProduct */
        foreach ($csvWishlistProducts as $csvWishlistProduct) {
            if ($this->csvWishlistProductIsValid($csvWishlistProduct)) {
                $variantIdRequestAttributes[] = $csvWishlistProduct->getVariantId();
                $request->attributes->set('variantId', $variantIdRequestAttributes);
            }
        }
        if (!$this->csvWishlistProductIsValid($csvWishlistProduct)) {
            $this->flashBag->add('error', $this->translator->trans('bitbag_sylius_wishlist_plugin.ui.csv_file_contains_incorrect_products'));
        }
    }

    private function fileIsValidMimeType(\SplFileInfo $fileInfo): bool
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);

        return in_array($finfo->file($fileInfo->getRealPath()), $this->allowedMimeTypes);
    }

    private function csvWishlistProductIsValid(CsvWishlistProductInterface $csvWishlistProduct): bool
    {
        $wishlistProduct = $this->productVariantRepository->findOneBy([
            'id' => $csvWishlistProduct->getVariantId(),
            'product' => $csvWishlistProduct->getProductId(),
            'code' => $csvWishlistProduct->getVariantCode(),
        ]);

        if (null === $wishlistProduct) {
            return false;
        }

        return true;
    }
}
