<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorSVG;

class ProductIdentificationService
{
    /**
     * Generate a Code 128 Barcode as an SVG string.
     */
    public function generateBarcodeSvg(string $identifier): string
    {
        $generator = new BarcodeGeneratorSVG;

        // Return SVG string
        return $generator->getBarcode($identifier, $generator::TYPE_CODE_128, 2, 60, 'black');
    }

    /**
     * Generate a QR Code as an SVG string.
     */
    public function generateQrCodeSvg(string $identifier): string
    {
        $options = new QROptions([
            'version' => 5,
            'outputType' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::L,
            'svgViewBoxSize' => 100,
            'addQuietzone' => false,
        ]);

        $qrcode = new QRCode($options);
        // Clean up the output SVG slightly to allow CSS styling via currentColor
        $svg = $qrcode->render($identifier);

        // Remove XML declaration if present
        $svg = (string) preg_replace('/<\?xml.*?\?>/i', '', $svg);

        // Replace fixed black fill with currentColor for dark mode support
        // But chillerlan uses rect with fill="black" or similar. By default, foreground is #000000.
        $svg = (string) str_replace(['fill="#000000"', 'fill="#000"'], 'fill="currentColor"', $svg);

        return trim($svg);
    }
}
