<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooPdfInvoice')) {

/**
 * PDF generation class
 *
 * @class WooPDFInvoice
 * @package WooCommerce_PDF_Invoice
 * @author RightPress
 */
class WooPdfInvoice extends TCPDF
{

    private $invoiceOptions;
    private $orderData;
    private $invoiceInfo;
    private $current_page;
    private $page_count;
    private $wc_countries;

    /**
     * Class constructor
     *
     * @access public
     * @param array $data
     * @param string $orientation
     * @param string $unit
     * @param string $format
     * @return void
     */
    function __construct($data, $orientation, $unit, $format)
    {

        parent::__construct($orientation, $unit, $format, true, 'UTF-8', false);

        // Development settings
        $this->showBorders = 0;

        // Parse order data
        $this->invoiceOptions = $data['options'];
        $this->orderData = $data['order'];
        $this->invoiceInfo = $data['info'];
        $this->invoiceType = $data['type'];

        $this->order_tax = null;
        $this->totals = null;

        // Check if different tax rates are used for different items and if so - get total for each of them
        $this->multiple_tax_rates = $this->maybe_get_multiple_tax_totals();

        // Convert HTML entities to regular characters
        $invoice_options = $this->invoiceOptions;
        $this->invoiceOptions = array();
        foreach ($invoice_options as $key => $value) {
            $this->invoiceOptions[$key] = htmlspecialchars_decode($value);
        }

        // Initial setup
        $this->footer_height = $this->get_height('footer');
        $this->max_first_page_height = 800 - $this->footer_height;
        $this->max_page_height = 800 - $this->footer_height;
        $this->current_page = 1;
        $this->page_count = 1;

        // Set document meta-information
        $this->SetCreator(PDF_CREATOR);
        $this->SetAuthor($this->invoiceOptions['woo_pdf_seller_name']);
        $document_title = ($this->invoiceType == 'invoice' ?
                $this->invoiceOptions['woo_pdf_document_name'] . ' ' . $this->invoiceInfo['prefix'] . ' ' . $this->invoiceInfo['id'] . ' ' . $this->invoiceInfo['suffix'] :
                $this->invoiceOptions['woo_pdf_proforma_name'] . ' ' . $this->invoiceInfo['id']);
        $this->SetTitle($document_title);
        $this->SetSubject($this->invoiceOptions['woo_pdf_document_name']);
        $this->SetKeywords($this->invoiceOptions['woo_pdf_seller_name'], $this->invoiceInfo['id']);

        // Set the page margins: 36pt on all sides
        $this->SetMargins(36, 36, 36, true);
        $this->SetAutoPageBreak(true, 36);

        // Set image scale factor
        $this->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Set some language-dependent strings
        global $l;
        $this->setLanguageArray($l);

    }

    /**
     * Create PDF invoice
     *
     * @access public
     * @return void
     */
    public function CreateInvoice()
    {
        // Add first page
        $this->SetPrintHeader(false);
        $this->SetPrintFooter(false);
        $this->AddPage();

        // Track pointer
        $y = 40;
        $r = 12;
        $s = 5;

        $y = $this->render_first_page_header($y, $r, $s);

        // Padding after header
        $y = $y + 40;

        $y = $this->render_table_header($y, $r, $s);

        // Padding after table header
        $y = $y + 4;

        $this->style();

        if (sizeof($this->orderData->get_items()) > 0) {
            foreach ($this->orderData->get_items() as $item) {

                $_product = $this->orderData->get_product_from_item($item);
                $item_obj = new WC_Order_Item_Meta($item['item_meta']);
                $item_meta = $this->item_meta($item_obj);

                // Maybe prepend short product description
                if (is_object($_product)) {
                    if ($this->invoiceOptions['woo_pdf_display_short_description'] && !empty($_product->post->post_excerpt)) {
                        $post_excerpt = $this->invoiceOptions['woo_pdf_title_description'] . ': ' . strip_tags($_product->post->post_excerpt);
                        $item_meta = empty($item_meta) ? $post_excerpt : $post_excerpt . PHP_EOL . $item_meta;
                    }
                }

                // Maybe prepend list of product categories
                if (is_object($_product)) {
                    if ($this->invoiceOptions['woo_pdf_display_category'] && $current_item_categories = $this->get_product_category_names($_product->id)) {
                        $current_item_categories = $this->invoiceOptions['woo_pdf_title_category'] . ': ' . join(', ', $current_item_categories);
                        $item_meta = empty($item_meta) ? $current_item_categories : $current_item_categories . PHP_EOL . $item_meta;
                    }
                }

                $current_meta_height = $this->get_height('meta', $item_meta);

                // Maybe display product images
                $product_images = array();
                $current_images_height = 0;

                if (is_object($_product)) {
                    if ($this->invoiceOptions['woo_pdf_display_product_thumbnails']) {
                        $product_image_ids = $_product->get_gallery_attachment_ids();

                        if (is_array($product_image_ids) && !empty($product_image_ids)) {
                            $product_images = (count($product_image_ids) > 5) ? array_slice($product_image_ids, 0, 5) : $product_image_ids;
                            $current_images_height = 100;
                        }
                    }
                }

                // Add new page if needed
                if (($y + $s + $r + 2 + (empty($item_meta) ? 0 : ($current_meta_height + 2)) + $current_images_height + 20) > $this->max_first_page_height) {
                    $y = $this->add_page();
                }

                $row = array(
                    'name' => $item['name'],
                    'price' => $this->item_price($item, $this->orderData),
                    'quantity' => $item['qty'],
                    'meta' => $item_meta,
                    'images' => $product_images,
                    'net' => $item['line_subtotal'],
                    'tax_rate' => $this->get_item_tax_rate($item['line_subtotal'], $item['line_subtotal_tax']),
                    'tax' => $item['line_subtotal_tax'],
                    'total' => $this->row_total($item, $this->orderData),
                );

                // Check if we need to display product ID/SKU
                $item_name = $row['name'];
                if ($this->invoiceOptions['woo_pdf_display_product_id'] == 1) {
                    $item_name = $item['product_id'] . ' - ' . $item_name;
                }
                else if ($this->invoiceOptions['woo_pdf_display_product_id'] == 2 && is_object($_product)) {
                    $item_sku = $_product->get_sku();
                    if ($item_sku != '')
                        $item_name = $item_name . ' - ' . $item_sku;
                }

                // Maybe display more decimals to prevent issues because of rounding
                $price = $this->get_rounded_price($row['price'], $row['quantity']);

                // Different tax rates used
                if (($this->invoiceOptions['woo_pdf_display_tax_inline'] == 0 && $this->multiple_tax_rates) || $this->invoiceOptions['woo_pdf_display_tax_inline'] == 1) {

                    // Name
                    $this->MultiCell(200, $r, $this->decode($item_name), $this->showBorders, 'R', 0, 1, 360, $y, true, 0, false, false, 0, 'T', false);

                    // Get real row height
                    $true_r = $this->getY() - $y;

                    // Price
                    $this->MultiCell(60, $true_r, $price, $this->showBorders, 'R', 0, 1, 300, $y, true, 0, false, false, $true_r, 'T', false);

                    // Quantity
                    $this->MultiCell(40, $true_r, $row['quantity'], $this->showBorders, 'R', 0, 1, 260, $y, true, 0, false, false, $true_r, 'T', false);

                    // Net
                    $this->MultiCell(60, $true_r, $this->format_currency($row['net']), $this->showBorders, 'R', 0, 1, 200, $y, true, 0, false, false, $true_r, 'T', false);

                    // Tax %
                    $this->MultiCell(50, $true_r, $row['tax_rate'] . ' %', $this->showBorders, 'R', 0, 1, 150, $y, true, 0, false, false, $true_r, 'T', false);

                    // Tax
                    $this->MultiCell(50, $true_r, $this->format_currency($row['tax']), $this->showBorders, 'R', 0, 1, 100, $y, true, 0, false, false, $true_r, 'T', false);

                    // Total
                    $this->MultiCell(60, $true_r, $this->format_currency($row['total']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, $true_r, 'T', false);

                    // Meta
                    if (!empty($row['meta'])) {
                        $y += $true_r + 2;
                        $this->style('gray');
                        $this->MultiCell(515, 0, $this->decode($row['meta']), $this->showBorders, 'L', 0, 1, 45, $y, true, 0, false, false, 0, 'T', false);
                        $y += $current_meta_height;
                        $this->style();
                    }

                    // Images
                    if (!empty($row['images'])) {
                        $y += empty($row['meta']) ? ($true_r + 7) : 0;

                        $i = 0;

                        foreach ($row['images'] as $attachment_id) {
                            $product_image_url = wp_get_attachment_url($attachment_id);
                            $this->Image($product_image_url, (50 + ($i * 100)), $y, 90, 90, '', '', 'T', true, 300, '', false, false, $this->showBorders, true, false, false, false, false);
                            $i++;
                        }

                        $y += $current_images_height;
                    }

                    $y += $s + ((!empty($row['meta']) || !empty($row['images'])) ? 0 : $true_r);
                }

                // Different tax rates not used or feature disabled
                else {

                    // Name
                    $this->MultiCell(280, $r, $this->decode($item_name), $this->showBorders, 'R', 0, 1, 280, $y, true, 0, false, false, 0, 'T', false);

                    // Get real row height
                    $true_r = $this->getY() - $y;

                    // Price
                    $this->MultiCell(80, $true_r, $price, $this->showBorders, 'R', 0, 1, 200, $y, true, 0, false, false, $true_r, 'T', false);

                    // Quantity
                    $this->MultiCell(80, $true_r, $row['quantity'], $this->showBorders, 'R', 0, 1, 140, $y, true, 0, false, false, $true_r, 'T', false);

                    // Total
                    $this->MultiCell(100, $true_r, $this->format_currency($row['total']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, $true_r, 'T', false);

                    // Meta
                    if (!empty($row['meta'])) {
                        $y += $true_r + 2;
                        $this->style('gray');
                        $this->MultiCell(515, 0, $this->decode($row['meta']), $this->showBorders, 'L', 0, 1, 45, $y, true, 0, false, false, 0, 'T', false);
                        $y += $current_meta_height;
                        $this->style();
                    }

                    // Images
                    if (!empty($row['images'])) {
                        $y += empty($row['meta']) ? ($true_r + 7) : 0;

                        $i = 0;

                        foreach ($row['images'] as $attachment_id) {
                            $product_image_url = wp_get_attachment_url($attachment_id);
                            $this->Image($product_image_url, (50 + ($i * 100)), $y, 90, 90, '', '', 'T', true, 300, '', false, false, $this->showBorders, true, false, false, false, false);
                            $i++;
                        }

                        $y += $current_images_height;
                    }

                    $y += $s + ((!empty($row['meta']) || !empty($row['images'])) ? 0 : $true_r);
                }

                // Draw bottom border
                $this->Line(40, ($y-2), 560, ($y-2), array('color' => array(192, 192, 192)));
                $y += 2;
            }
        }

        $y = $this->render_totals($y, $r, $s);

        // Reset cell padding
        $this->SetCellPaddings(2.835, 0, 2.835, 0);

        // Amount in words
        if ($this->invoiceOptions['woo_pdf_amount_in_words']) {
            $y = $this->render_left_block($y, $r, $s, 'amount_in_words');
        }

        // Render custom content blocks
        foreach (array('1', '2', '3', '4') as $n) {
            if ($this->invoiceOptions['woo_pdf_block_'.$n.'_show'] == 0 || ($this->invoiceOptions['woo_pdf_block_'.$n.'_show'] == 1 && $this->invoiceType == 'invoice') || ($this->invoiceOptions['woo_pdf_block_'.$n.'_show'] == 2 && $this->invoiceType == 'proforma')) {
                if ($this->invoiceOptions['woo_pdf_block_'.$n.'_title'] != '' || $this->invoiceOptions['woo_pdf_block_'.$n.'_content'] != '') {
                    $y = $this->render_left_block($y, $r, $s, 'block_'.$n);
                }
            }
        }

        $this->render_footer();

        if ($this->page_count > 1) {
            $this->render_page_numbers();
        }
    }

    /**
     * Determine height of element
     *
     * @access public
     * @param string $context
     * @param string $string
     * @return mixed
     */
    public function get_height($context, $string = '', $s = 0)
    {
        // Get current page
        $current_page = $this->getPage();

        // Add blank page to test height
        $this->AddPage();
        $this->SetY(0);

        // Test render string (depending on context)
        if ($context == 'footer') {
            $this->render_footer(true);
        }
        else if ($context == 'meta') {
            $this->MultiCell(515, 0, $this->decode($string), $this->showBorders, 'L', 0, 1, 45, 0, true, 0, false, false, 0, 'T', false);
        }
        else if ($context == 'block') {

            $block_strings = explode(PHP_EOL, $this->decode($string));
            $allowed_height = $this->current_page == 1 ? $this->max_first_page_height : $this->max_page_height;
            $y = 0;

            // Iterate through strings
            foreach ($block_strings as $string) {

                $this->MultiCell(520, 0, $string, $this->showBorders, 'L', 0, 1, 40, $y, true, 0, false, false, 0, 'T', false);
                $y = $this->GetY();

                // Stop if it exceeds one page
                if (($y + (20 + 15 + $s + 20)) > $allowed_height) {
                    break;
                }
            }
        }

        // Get current test page
        $test_page = $this->getPage();

        // Get y position and remove the page
        $y = $this->getY();

        // Make sure to delete all test pages
        for ($i = $current_page + 1; $i <= $test_page; $i++) {
            $this->deletePage($i);
        }

        return $y;
    }

    /**
     * Render table header
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @return void
     */
    public function render_table_header($y = 240, $r = 0, $s = 0)
    {
        $this->style('b');
        $this->SetCellPadding(5);

        // Different tax rates used
        if (($this->invoiceOptions['woo_pdf_display_tax_inline'] == 0 && $this->multiple_tax_rates) || $this->invoiceOptions['woo_pdf_display_tax_inline'] == 1) {

            // Name
            $this->MultiCell(200, 20, $this->decode($this->invoiceOptions['woo_pdf_title_product']), $this->showBorders, 'R', 0, 1, 360, $y, true, 0, false, false, 20, 'M', false);

            // Price
            $this->MultiCell(60, 20, $this->decode($this->invoiceOptions['woo_pdf_title_price']), $this->showBorders, 'R', 0, 1, 300, $y, true, 0, false, false, 20, 'M', false);

            // Quantity
            $this->MultiCell(40, 20, $this->decode($this->invoiceOptions['woo_pdf_title_quantity']), $this->showBorders, 'R', 0, 1, 260, $y, true, 0, false, false, 20, 'M', false);

            // Net
            $this->MultiCell(60, 20, $this->decode($this->invoiceOptions['woo_pdf_title_net']), $this->showBorders, 'R', 0, 1, 200, $y, true, 0, false, false, 20, 'M', false);

            // Tax %
            $this->MultiCell(50, 20, $this->decode($this->invoiceOptions['woo_pdf_title_tax_percent']), $this->showBorders, 'R', 0, 1, 150, $y, true, 0, false, false, 20, 'M', false);

            // Tax
            $this->MultiCell(50, 20, $this->decode($this->invoiceOptions['woo_pdf_title_tax']), $this->showBorders, 'R', 0, 1, 100, $y, true, 0, false, false, 20, 'M', false);

            // Total
            $this->MultiCell(60, 20, $this->decode($this->invoiceOptions['woo_pdf_title_line_total']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, 20, 'M', false);

        }

        // Different tax rates not used or feature disabled
        else {

            // Name
            $this->MultiCell(280, 20, $this->decode($this->invoiceOptions['woo_pdf_title_product']), $this->showBorders, 'R', 0, 1, 280, $y, true, 0, false, false, 20, 'M', false);

            // Price
            $this->MultiCell(80, 20, $this->decode($this->invoiceOptions['woo_pdf_title_price']), $this->showBorders, 'R', 0, 1, 200, $y, true, 0, false, false, 20, 'M', false);

            // Quantity
            $this->MultiCell(80, 20, $this->decode($this->invoiceOptions['woo_pdf_title_quantity']), $this->showBorders, 'R', 0, 1, 140, $y, true, 0, false, false, 20, 'M', false);

            // Total
            $this->MultiCell(100, 20, $this->decode($this->invoiceOptions['woo_pdf_title_line_total']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, 20, 'M', false);

        }

        // Draw bottom border
        $this->Line(40, ($y+20), 560, ($y+20), array('color' => array(192, 192, 192)));

        return $this->getY();
    }

    /**
     * Render totals
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @return int
     */
    public function render_totals($y, $r, $s)
    {
        // Get totals
        if (is_null($this->totals)) {
            $this->totals = $this->get_totals();
        }

        // Calculate proposed height
        $height = 10 + 15; // adding 15 for higher than usual "totals" field
        foreach ($this->totals as $key => $value) {
            if (!is_null($value['value'])) {
                $count = is_array($value['value']) ? count($value['value']) : 1;
                $height += ($r + $s) * $count;
            }
        }

        // Add new page if needed
        $allowed_height = $this->current_page == 1 ? $this->max_first_page_height : $this->max_page_height;
        if (($y + $height) > $allowed_height) {
            $y = $this->add_page(false);
        }

        // Draw top border
        $this->Line(40, ($y-2), 560, ($y-2), array('color' => array(192, 192, 192)));
        $y += 10;

        foreach ($this->totals as $key => $value) {
            if (is_null($value['value'])) {
                continue;
            }

            // Check if we need to show tax rows
            if ($key == 'taxes' && ($this->invoiceOptions['woo_pdf_list_tax'] == 0 || ($this->invoiceOptions['woo_pdf_list_tax'] == 2 && $this->invoiceOptions['woo_pdf_display_tax_inline'] == 0 && $this->multiple_tax_rates))) {
                continue;
            }

            // Check if tax has been displayed inline - in this case only shipping tax needs to be displayed here
            if ($key == 'taxes' && $this->multiple_tax_rates && $this->invoiceOptions['woo_pdf_list_tax'] == '2') {

                if (isset($this->orderData->order_shipping) && isset($this->orderData->order_shipping_tax)) {
                    if ((double) $this->orderData->order_shipping > 0 && (double) $this->orderData->order_shipping_tax > 0) {

                        // Get shipping tax rate
                        $shipping_tax_rate = $this->get_item_tax_rate((double) $this->orderData->order_shipping, (double) $this->orderData->order_shipping_tax);

                        // Display shipping tax
                        $field_name = $this->invoiceOptions['woo_pdf_title_shipping_tax'] . ' (' . floatval($shipping_tax_rate) . ' %)';
                        $this->MultiCell(120, $r, $this->decode($field_name), $this->showBorders, 'R', 0, 1, 140, $y, true, 0, false, false, $r, 'T', false);
                        $this->MultiCell(100, $r, $this->format_currency((double) $this->orderData->order_shipping_tax), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, $r, 'T', false);
                        $y += $r + $s;
                    }
                }

                continue;
            }

            // If we reached this point, proceed normally
            if (is_array($value['value'])) {
                foreach ($value['value'] as $code => $field) {
                    $field_name = (isset($field['rate'])) ? $field['name'] . ' (' . floatval($field['rate']) . ' %)' : $field['name'];
                    $this->MultiCell(120, $r, $this->decode($field_name), $this->showBorders, 'R', 0, 1, 140, $y, true, 0, false, false, $r, 'T', false);
                    $this->MultiCell(100, $r, $this->format_currency($field['amount']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, $r, 'T', false);
                    $y += $r + $s;
                }

                continue;
            }

            // Set up totals row
            $auto_padding = ($key != 'total') ? false : true;
            $borders_name = ($key != 'total') ? $this->showBorders : array('TRB' => array('width' => 1, 'color' => array(0, 0, 0)));
            $borders_value = ($key != 'total') ? $this->showBorders : array('LTB' => array('width' => 1, 'color' => array(0, 0, 0)));

            // Name
            $name = ($key == 'total') ? $value['name'] : $value['name'];
            $this->MultiCell(120, $r, $this->decode($name), $borders_name, 'R', 0, 1, 140, $y, true, 0, false, $auto_padding, $r, 'T', false);

            // Value
            $this->MultiCell(100, $r, $this->format_currency($value['value']), $borders_value, 'R', 0, 1, 40, $y, true, 0, false, $auto_padding, $r, 'T', false);

            $y += $r + $s + ($key == 'total' ? 10 : 0);

            $this->style();
        }

        return $y;

    }

    /**
     * Render left block
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @param string $type
     * @return int
     */
    public function render_left_block($y, $r, $s, $type)
    {
        // Define blocks
        $blocks = array(
            'amount_in_words' => array(
                'title' => $this->invoiceOptions['woo_pdf_title_amount_in_words'],
                'text' => $this->get_amount_in_words((double) $this->orderData->order_total, $this->invoiceOptions),
            ),
        );

        // Define all custom blocks
        foreach (array('1', '2', '3', '4') as $n) {
            $blocks['block_'.$n] = array(
                'title' => $this->replace_macros($this->invoiceOptions['woo_pdf_block_'.$n.'_title'], false),
                'text' => $this->replace_macros($this->invoiceOptions['woo_pdf_block_'.$n.'_content'], $this->invoiceOptions['woo_pdf_block_'.$n.'_remove_empty_lines']),
            );
        }

        // Get allowed height
        $allowed_height = $this->current_page == 1 ? $this->max_first_page_height : $this->max_page_height;

        $y += 10;
        $this->style('b');
        $this->MultiCell(520, $r, $this->decode($blocks[$type]['title']), $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, 20, 'M', false);
        $this->style();
        $y += 15 + $s;

        // Break down the block to strings
        $block_strings = explode(PHP_EOL, $this->decode($blocks[$type]['text']));

        // Iterate through strings
        foreach ($block_strings as $string) {

            $current_content_height = $this->get_height('block', $string, $s);

            if (($y + (20 + 15 + $s + $current_content_height + 20)) > $allowed_height) {
                $y = $this->add_page(false);
            }

            $this->MultiCell(520, 0, $string, $this->showBorders, 'R', 0, 1, 40, $y, true, 0, false, false, 0, 'T', false);
            $y = $this->GetY();
        }

        return $this->GetY();
    }

    /**
     * Add new page
     *
     * @access public
     * @param bool $has_products
     * @return int
     */
    public function add_page($has_products = true)
    {
        // Add footer to current page
        $this->render_footer();

        // Add new page
        $this->AddPage();

        // Print header
        $this->render_page_header();

        $this->style('gray');
        $this->MultiCell(100, 12, $this->decode($this->invoiceOptions['woo_pdf_title_additional_page']), $this->showBorders, 'L', 0, 1, 40, 40, true, 0, false, false, 0, 'M', false);
        $this->style();

        if ($has_products) {
            $this->render_table_header(120);
            $this->style();
        }

        // Increment counters
        $this->current_page = $this->page_count + 1;
        $this->page_count++;

        // Reset y
        $y = $has_products ? 145 : 130;

        return $y;
    }

    /**
     * Render page numbers on all pages
     *
     * @access public
     * @return void
     */
    public function render_page_numbers()
    {
        $page_number = $this->page_count;
        while ($page_number) {
            $this->setPage($page_number);
            $this->style('gray');
            $this->MultiCell(100, 12, $this->decode($this->invoiceOptions['woo_pdf_title_page']) . ' ' . $page_number . ' / ' . $this->page_count, $this->showBorders, 'R', 0, 1, 460, 790, true, 0, false, false, 0, 'M', false);
            $this->style();
            $page_number--;
        }
    }

    /**
     * Try to get seller logo
     *
     * @access public
     * @return mixed
     */
    public function get_seller_logo()
    {
        $logo = $this->invoiceOptions['woo_pdf_seller_logo'];

        if (empty($logo)) {
            return false;
        }

        // If allow_url_fopen is active, use getimagesize to detect image
        if (ini_get('allow_url_fopen')) {

            if (is_array(getimagesize($logo))) {
                return $logo;
            }

            return false;
        }

        // Otherwise, get image by CURL, use imagecreatefromstring to detect image
        else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $logo);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $logo_data = curl_exec($ch);

            if (imagecreatefromstring($logo_data)) {
                return $logo;
            }

            return false;
        }
    }

    /**
     * Render first page header
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @return void
     */
    public function render_first_page_header($y, $r, $s)
    { 
        $y_right_block = $y;
				
				
        // Show seller details block
        $y_right_block = $this->render_seller_details($y_right_block, $r, $s);

        // Space between seller and buyer details
        $y_right_block = $y_right_block + 20;

        // Show buyer details block
        $y_right_block = $this->render_buyer_details($y_right_block, $r, $s);

        // Show logo if set
        if ($seller_logo = $this->get_seller_logo()) {
            $resize_factor = (isset($this->invoiceOptions['woo_pdf_seller_logo_resize']) && is_numeric($this->invoiceOptions['woo_pdf_seller_logo_resize'])) ? ($this->invoiceOptions['woo_pdf_seller_logo_resize'] / 100) : 1;
            $this->Image($seller_logo, 400, $y, (150 * $resize_factor), (100 * $resize_factor), '', '', 'T', true, 300, 'L', false, false, $this->showBorders, true, false, false, false, false);
            $y = $this->getImageRBY();
        }
        else {
            $y = 40;
        }

        if (($y + 20 + 40) < $y_right_block) {
            $y = $y_right_block - 40;
        }
        else {
            $y = $y + 20;
        }

        // Invoice details
        $invoice_info_content = $this->replace_macros($this->invoiceOptions['woo_pdf_invoice_info_content'], $this->invoiceOptions['woo_pdf_invoice_info_remove_empty_lines']);
        $inv_strings = explode(PHP_EOL, $invoice_info_content);
        $first_line = true;

        // Iterate through strings
        foreach ($inv_strings as $string) {

            // Make first string bold
            if ($first_line) {
                $first_line = false;
                $this->style('b');
                // leaving $y_right_block unchanged
            }

            // And default style for others
            else {
                $this->style();
                $y = $this->GetY() + 2;
            }

            // Explode string to 2 parts for left and right columns
            $stringarr = explode('|', $string);

            // Print left column if set
            if (isset($stringarr[0])) {
                $this->MultiCell(140, 0, $stringarr[1], $this->showBorders, 'R', 0, 1, 20, $y, true, 0, false, false, 80, 'T', false);
            }

            // Print right column if set
            if (isset($stringarr[1])) {
                $this->MultiCell(140, 0, $stringarr[0], $this->showBorders, 'R', 0, 1, 110, $y, true, 0, false, false, 80, 'T', false);

            }
        }

        return $this->GetY();
    }

    /**
     * Render page header (all pages except first)
     *
     * @access public
     * @return void
     */
    public function render_page_header()
    {

        // Invoice number
        $this->style('b');
        $document_name = $this->invoiceType == 'invoice' ? $this->invoiceOptions['woo_pdf_document_name'] : $this->invoiceOptions['woo_pdf_proforma_name'];
        $this->MultiCell(145, 0, $this->decode($document_name), $this->showBorders, 'L', 0, 1, 320, 40, true, 0, false, false, 80, 'T', false);
        $this->MultiCell(95, 0, $this->decode($this->invoiceInfo['prefix']) . ' ' . $this->invoiceInfo['id'] . ' ' . $this->decode($this->invoiceInfo['suffix']), $this->showBorders, 'L', 0, 1, 465, 40, true, 0, false, false, 80, 'T', false);

        // Invoice date
        $this->style();
        $this->MultiCell(145, 0, $this->decode($this->invoiceOptions['woo_pdf_title_date']), $this->showBorders, 'L', 0, 1, 320, 55, true, 0, false, false, 80, 'T', false);
        $this->MultiCell(95, 0, $this->date(), $this->showBorders, 'R', 0, 1, 465, 55, true, 0, false, false, 80, 'T', false);

        // Order amount
        $this->style();
        $this->MultiCell(145, 0, $this->decode($this->invoiceOptions['woo_pdf_title_amount']) . ' (' . $this->decode($this->currency(false)) . ')', $this->showBorders, 'L', 0, 1, 320, 68, true, 0, false, false, 80, 'T', false);
        $this->MultiCell(95, 0, $this->format_currency($this->total()), $this->showBorders, 'R', 0, 1, 465, 68, true, 0, false, false, 80, 'T', false);

    }

    /**
     * Render seller details block (6 lines at most)
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @return void
     */
    public function render_seller_details($y_right_block, $r, $s)
    {
        // Seller block title
        $this->style('gray');
        $this->MultiCell(520, 0, $this->decode($this->invoiceOptions['woo_pdf_title_seller']), $this->showBorders, 'R', 0, 1, 40, 40, true, 0, false, false, 0, 'T', false);

        // Set black bold font
        $this->style(array('size' => 8, 'style' => 'b'));

        // Seller name
        if (!empty($this->invoiceOptions['woo_pdf_seller_name'])) {
            $this->MultiCell(520, 0, $this->decode($this->invoiceOptions['woo_pdf_seller_name']), $this->showBorders, 'R', 0, 1, 40, ($this->GetY() + 2), true, 0, false, false, 0, 'T', false);
        }

        // Seller details
        $this->style(array('size' => 8));
        $this->MultiCell(520, 0, $this->decode($this->invoiceOptions['woo_pdf_seller_content']), $this->showBorders, 'R', 0, 1, 40, ($this->GetY() + 2), true, 0, false, false, 0, 'T', false);

        $y_right_block = $this->GetY();
        $y_right_block = ($y_right_block < 100) ? 100 : $y_right_block;

        return $y_right_block;
    }

    /**
     * Render buyer details block (7 lines at most)
     *
     * @access public
     * @param int $y
     * @param int $r
     * @param int $s
     * @return void
     */
    public function render_buyer_details($y_right_block, $r, $s)
    {
        $buyer_content = '';

        // Seller block title
        $this->style('gray');
        $this->MultiCell(520, 0, $this->decode($this->invoiceOptions['woo_pdf_title_buyer']), $this->showBorders, 'R', 0, 1, 40, $y_right_block, true, 0, false, false, 0, 'T', false);

        // Set bold font
        $this->style('b');

        // Buyer company and name
        $name = array();
        if (!empty($this->orderData->billing_first_name)) {
            array_push($name, $this->orderData->billing_first_name);
        }
        if (!empty($this->orderData->billing_last_name)) {
            array_push($name, $this->orderData->billing_last_name);
        }

        if (!empty($this->orderData->billing_company)) {
            $this->MultiCell(520, 0, $this->decode($this->orderData->billing_company), $this->showBorders, 'R', 0, 1, 40, ($this->GetY() + 2), true, 0, false, false, 0, 'T', false);
            if (!empty($name)) {
                $buyer_content .= join(' ', $name) . PHP_EOL;
            }
        }
        else if (!empty($name)) {
            $this->MultiCell(520, 0, $this->decode(join(' ', $name)), $this->showBorders, 'R', 0, 1, 40, ($this->GetY() + 2), true, 0, false, false, 0, 'T', false);
        }

        // Buyer details
        $buyer_content .= $this->replace_macros($this->invoiceOptions['woo_pdf_buyer_content'], $this->invoiceOptions['woo_pdf_buyer_remove_empty_lines']);

        $this->style();
        $this->MultiCell(520, 0, $this->decode($buyer_content), $this->showBorders, 'R', 0, 1, 40, ($this->GetY() + 2), true, 0, false, false, 0, 'T', false);

        $y_right_block = $this->GetY();
        $y_right_block = ($y_right_block < 180) ? 180 : $y_right_block;

        return $y_right_block;
    }

    /**
     * Render page footer
     *
     * @access public
     * @return void
     */
    public function render_footer($is_test = false)
    {
        if (!empty($this->invoiceOptions['woo_pdf_footer'])) {

            $start_y = $is_test ? 0 : (780 - $this->footer_height);

            $this->style('footer');
            $this->Line(40, $start_y, 560, $start_y, array('color' => array(192, 192, 192)));
            $this->MultiCell(520, 0, $this->decode($this->replace_macros($this->invoiceOptions['woo_pdf_footer'], $this->invoiceOptions['woo_pdf_footer_remove_empty_lines'])), $this->showBorders, 'C', 0, 1, 40, ($start_y + 10), true, 0, false, false, 500, 'T', false);
        }
    }


    /**
     * Set text style
     *
     * @access public
     * @param string/array $input
     * @return void
     */
    public function style($input = null)
    {
        // General style
        $style = array(
                'family' => 'dejavusans',
                'style' => '',
                'size' => 9,
                'color' => '#000000'
        );

        // Define styles
        $styles = array(
            'b' => array(
                'style' => 'b',
            ),
            'gray' => array(
                'size' => 9,
                'color' => '#C0C0C0',
            ),
            'footer' => array(
                'size' => 9,
            ),
        );

        // Make requested style
        if (!empty($input) && !is_array($input)) {
            $style = array_merge($style, $styles[$input]);
        }
        else if (!empty($input) && is_array($input)) {
            $style = array_merge($style, $input);
        }

        // Allow to override styles
        $style = apply_filters('woo_pdf_styles', $style);

        list($r, $g, $b) = $this->hex_rgb($style['color']);

        $this->SetFont($style['family'], $style['style'], $style['size']);
        $this->SetTextColor($r, $g, $b);
    }


    /**
     * Convert hex color to rgb
     *
     * @access public
     * @param string $hex
     * @return array
     */
    public function hex_rgb($hex)
    {
        $hex = str_replace("#", "", $hex);

        if(strlen($hex) == 3) {
           $r = hexdec(substr($hex,0,1).substr($hex,0,1));
           $g = hexdec(substr($hex,1,1).substr($hex,1,1));
           $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
           $r = hexdec(substr($hex,0,2));
           $g = hexdec(substr($hex,2,2));
           $b = hexdec(substr($hex,4,2));
        }

        return array($r, $g, $b);
    }

    /**
     * Get formatted date
     *
     * @access public
     * @param string $real_date
     * @return string
     */
    public function date($real_date = false)
    {
        $formats = array(
            '0' => 'n/j/y',
            '1' => 'n/j/Y',
            '2' => 'j/n/y',
            '3' => 'j/n/Y',
            '4' => 'y-m-d',
            '5' => 'Y-m-d',
            '6' => 'F j, Y',
            '7' => 'd.m.Y',
            '8' => 'd-m-Y',
        );

        $format = apply_filters('woo_pdf_date_format', $formats[$this->invoiceOptions['woo_pdf_date_format']]);
    			
    			if ($this->invoiceType == 'proforma' || $real_date) {
            $order_date_timestamp = strtotime($this->orderData->order_date);
			
		
					/* Modify gregorian to jalali by ARS NETWORK (M) SDN BHD 	*/
          /* website: www.ars-network.com														*/
          /* email: developer@ars-network.com  											*/
          /* date format must to set dd/mm/yy #2										*/
            
    			if (apply_filters('woo_pdf_date_format', $this->invoiceOptions['woo_pdf_date_format'])==2) {        		
    				$gd=(date('j', $order_date_timestamp));
    				$gm=(date('n', $order_date_timestamp));
    				$gy=(date('Y', $order_date_timestamp));
    				$mod='/';

    				 $g_d_m=array(0,31,59,90,120,151,181,212,243,273,304,334);
						 $jy=($gy<=1600)?0:979;
						 $gy-=($gy<=1600)?621:1600;
						 $gy2=($gm>2)?($gy+1):$gy;
						 $days=(365*$gy) +((int)(($gy2+3)/4)) -((int)(($gy2+99)/100))+((int)(($gy2+399)/400)) -80 +$gd +$g_d_m[$gm-1];
						 $jy+=33*((int)($days/12053));
						 $days%=12053;
						 $jy+=4*((int)($days/1461));
						 $days%=1461;
						 $jy+=(int)(($days-1)/365);
						 if($days > 365)$days=($days-1)%365;
						 $jm=($days < 186)?1+(int)($days/31):7+(int)(($days-186)/30);
				     $jd=1+(($days < 186)?($days%31):(($days-186)%30));
							
						$jy = str_replace($search, $replace, $jy);
						$jm = str_replace($search, $replace, $jm);
						$jd = str_replace($search, $replace, $jd);
						
						
             return ($mod=='')?array($jy,$jm,$jd):$jy.$mod.$jm.$mod.$jd;
         }
         /* End of convert gregorian to jalali 									*/
            
            return date($format, $order_date_timestamp);
        }
        else {
            return date($format);
        }
    }

    /**
     * Get order total amount
     *
     * @access public
     * @return double
     */
    public function total()
    {
        return (double) $this->orderData->order_total;
    }

    /**
     * Format amount
     *
     * @access public
     * @param double $amount
     * @param int $decimals
     * @return string
     */
    public function format_amount($amount, $decimals = null)
    {
        $decimals = is_null($decimals) ? get_option('woocommerce_price_num_decimals') : $decimals;

        return number_format(
                   $amount,
                   $decimals,
                   get_option('woocommerce_price_decimal_sep'),
                   get_option('woocommerce_price_thousand_sep')
               );
    }

    /**
     * Format amount with currency symbol
     *
     * @access public
     * @param double $amount
     * @return string
     */
    public function format_currency($amount, $decimals = null)
    {
        if ($this->invoiceOptions['woo_pdf_display_currency_symbol'] && $currency_symbol = $this->decode($this->currency(true))) {
            // Add currency symbol
            $amount = preg_replace('/&nbsp;/', ' ', sprintf(get_woocommerce_price_format(), $currency_symbol, $this->format_amount((double) $amount, $decimals)));
        }
        else {
            $amount = $this->format_amount((double) $amount, $decimals);
        }
        
        /* Edited by ARS NETWORK (M) SDN BHD for convert Rials symbol to Character */
        if ($currency_symbol == 'ï·¼')
        $amount = str_replace('ï·¼','ر'.'یال',$amount);
        return $amount;
    }

    /**
     * Decode HTML special entities back to characters
     *
     * @access public
     * @param string $string
     * @return string
     */
    public function decode($string)
    {
        return html_entity_decode(htmlspecialchars_decode($string, ENT_QUOTES), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get order item meta information
     *
     * @access public
     * @param object $item
     * @return string
     */
    public function item_meta($item)
    {
        global $woocommerce;

        if (empty($item->meta)) {
            return;
        }

        $meta = array();
        $meta_string = '';

        foreach ($item->meta as $key => $values) {

            if (empty($values) || substr($key, 0, 1) == '_') {
                continue;
            }

            foreach ($values as $value) {

                if (is_serialized($value)) {
                    continue;
                }

                if (taxonomy_exists(esc_attr(str_replace('attribute_', '', $key)))) {
                    $term = get_term_by('slug', $value, esc_attr(str_replace('attribute_', '', $key)));
                    if (!is_wp_error($term) && $term->name) {
                        $value = $term->name;
                    }
                }

                if (WooPDF::wc_version_gte('2.1')) {
                    $meta[wc_attribute_label(str_replace('attribute_', '', $key))][] = $value;
                }
                else {
                    $meta[$woocommerce->attribute_label(str_replace('attribute_', '', $key))][] = $value;
                }
            }

        }

        $meta_final = array();

        // Glue values together
        foreach ($meta as $key => $values) {
            $meta_final[] = $key . ': ' . join(', ', $values) . '.';
        }

        // Make string with each property in separate line
        $meta_string = join(PHP_EOL, $meta_final);

        return $meta_string;
    }

    /**
     * Get single row total (excl. taxes)
     *
     * @access public
     * @param object $item
     * @param object $order
     * @return int
     */
    public function row_total($item, $order)
    {
        if (!isset($item['line_subtotal']) || !isset($item['line_subtotal_tax'])) {
            return;
        }

        $line_subtotal = ($this->invoiceOptions['woo_pdf_inclusive_tax'] || ($this->multiple_tax_rates && $this->invoiceOptions['woo_pdf_display_tax_inline'] == 0) || $this->invoiceOptions['woo_pdf_display_tax_inline'] == 1) ? ($item['line_subtotal'] + $item['line_subtotal_tax']) : $item['line_subtotal'];

        return (double) $line_subtotal;
    }

    /**
     * Calculate single item price (excl. taxes)
     *
     * @access public
     * @param object $item
     * @param object $order
     * @return string
     */
    public function item_price($item, $order)
    {
        if (!isset($item['line_subtotal']) || !isset($item['line_subtotal_tax']) || !isset($item['qty'])) {
            return;
        }

        $line_subtotal = $this->invoiceOptions['woo_pdf_inclusive_tax'] ? ((double) $item['line_subtotal'] + (double) $item['line_subtotal_tax']) : (double) $item['line_subtotal'];

        $price = $line_subtotal / (int) $item['qty'];

        if ((((double) $price) * (int) $item['qty']) == (double) $this->row_total($item, $this->orderData)) {
            return $price;
        }
        else {
            return $price;
        }
    }

    /**
     * Get unformatted subtotals, taxes and totals
     *
     * @access public
     * @return array
     */
    public function get_totals()
    {
        global $woocommerce;

        $totals = array();

        /**
         * SUBTOTAL
         */
        $subtotal = 0;

        foreach ($this->orderData->get_items() as $item) {
            if (isset($item['line_subtotal'])) {
                if ($this->invoiceOptions['woo_pdf_inclusive_tax'] || ($this->multiple_tax_rates && $this->invoiceOptions['woo_pdf_display_tax_inline'] == 0) || $this->invoiceOptions['woo_pdf_display_tax_inline'] == 1) {
                    $subtotal += floatval($item['line_subtotal']);

                    if (isset($item['line_subtotal_tax'])) {
                        $subtotal += floatval($item['line_subtotal_tax']);
                    }
                }
                else {
                    $subtotal += $this->orderData->get_line_subtotal($item);
                }
            }
        }

        $totals['subtotal'] = array(
            'name' => $this->invoiceOptions['woo_pdf_title_subtotal'],
            'value' => (double) $subtotal,
        );

        /**
         * CART DISCOUNT
         */
        $cart_discount = (double) $this->orderData->cart_discount + (double) $this->orderData->cart_discount_tax;
        $totals['cart_discount'] = ($cart_discount > 0) ? $cart_discount : null;

        if (!is_null($totals['cart_discount'])) {
            $totals['cart_discount'] = array(
                'name' => $this->invoiceOptions['woo_pdf_title_cart_discount'],
                'value' => $totals['cart_discount'],
            );
        }

        /**
         * SHIPPING
         */
        if ($this->orderData->order_shipping > 0) {
            $totals['shipping']  = (double) $this->orderData->order_shipping;
        }
        else if ($this->orderData->order_shipping == 0 && $this->invoiceOptions['woo_pdf_display_free_shipping']) {
            $totals['shipping']  = (double) 0;
        }
        else {
            $totals['shipping']  = null;
        }

        if ($totals['shipping'] != null && $this->invoiceOptions['woo_pdf_inclusive_tax']) {
            $totals['shipping'] += (double) $this->orderData->order_shipping_tax;
        }

        if (!is_null($totals['shipping'])) {
            $totals['shipping'] = array(
                'name' => $this->invoiceOptions['woo_pdf_title_shipping'],
                'value' => $totals['shipping'],
            );
        }

        /**
         * FEES
         */
        $totals['fees'] = array();

        if ($fees = $this->orderData->get_fees()) {
            foreach ($fees as $id => $fee) {
                $totals['fees'][$id] = array(
                    'name' => $fee['name'],
                    'amount' => (double) $fee['line_total']
                );

                if ($this->invoiceOptions['woo_pdf_inclusive_tax']) {
                    $totals['fees'][$id]['amount'] += (double) $fee['line_tax'];
                }
            }
        }

        if (empty($totals['fees'])) {
            $totals['fees'] = null;
        }

        if (!is_null($totals['fees'])) {
            $totals['fees'] = array(
                'name' => 'Fees',
                'value' => $totals['fees'],
            );
        }

        /**
         * TAXES
         */
        // Get all order tax rows if we don't have them yet
        if (is_null($this->order_tax)) {
            $this->order_tax = $this->get_order_tax();
        }

        $totals_taxes = array();

        foreach ($this->order_tax as $tax_key => $tax) {
            $totals_taxes[$tax_key] = array(
                'name'      => $tax['label'],
                'amount'    => ((float) $tax['tax_amount'] + (float) $tax['shipping_tax_amount'])
            );

            if (isset($tax['woo_pdf_tax_rate'])) {
                $totals_taxes[$tax_key]['rate'] = (float) $tax['woo_pdf_tax_rate'];
            }
        }

        if (empty($totals_taxes)) {
            $totals_taxes = null;
        }

        if (!is_null($totals_taxes)) {
            $totals_taxes = array(
                'name' => 'Taxes',
                'value' => $totals_taxes,
            );
        }

        /**
         *  Display taxes before total only if subtotal is displayed excluding taxes (so values sum up nicely)
         */
        if ($totals_taxes && !$this->invoiceOptions['woo_pdf_inclusive_tax']) {
            $totals['taxes'] = $totals_taxes;
        }

        /**
         * ORDER DISCOUNT
         */
        if (!WooPDF::wc_version_gte('2.3')) {
            $order_discount = (double) $this->orderData->get_total_discount();
            $totals['order_discount'] = ($order_discount > 0) ? $order_discount : null;

            if (!is_null($totals['order_discount'])) {
                $totals['order_discount'] = array(
                    'name' => $this->invoiceOptions['woo_pdf_title_order_discount'],
                    'value' => $totals['order_discount'],
                );
            }
        }

        /**
         * TOTAL
         */
        $totals['total'] = array(
            'name' => $this->invoiceOptions['woo_pdf_title_total'],
            'value' => (double) $this->orderData->order_total,
        );

        /**
         *  Display taxes below total only if subtotal is displayed including taxes
         */
        if ($totals_taxes && !isset($totals['taxes'])) {
            $totals['taxes'] = $totals_taxes;
        }

        /**
         * Optional total excl. tax row
         */
        if ($this->invoiceOptions['woo_pdf_total_excl_tax']) {
            $totals['total_excl_tax'] = array(
                'name' => $this->invoiceOptions['woo_pdf_title_total_excl_tax'],
                'value' => (double) ($this->orderData->get_total() - $this->orderData->get_total_tax()),
            );
        }

        return $totals;
    }

    /**
     * Check if order contains different tax rates and return totals for each tax rate if so
     *
     * @access public
     * @return mixed
     */
    public function maybe_get_multiple_tax_totals()
    {
        // Get order items
        $items = $this->orderData->get_items();

        $result = array();

        // Iterate over all items and extract tax rates and tax subtotals
        foreach ($items as $item) {
            if (isset($item['line_subtotal']) && isset($item['line_subtotal_tax'])) {

                // Calculate tax rate used for current item
                $tax_rate = $this->get_item_tax_rate($item['line_subtotal'], $item['line_subtotal_tax']);
                $result[(string) $tax_rate] = $item['line_subtotal_tax'] + (isset($result[(string) $tax_rate]) ? $result[(string) $tax_rate] : 0);

            }

        }

        // Do we have more than one tax rate in use
        if (!empty($result) && count($result) > 1) {
            return $result;
        }

        return false;
    }

    /**
     * Get tax rate for single item
     *
     * @access public
     * @param $line_subtotal
     * @param $line_subtotal_tax
     * @return float
     */
    public function get_item_tax_rate($line_subtotal, $line_subtotal_tax)
    {
        if (empty($line_subtotal) || !is_numeric($line_subtotal)) {
            return 0;
        }

        if (empty($line_subtotal_tax) || !is_numeric($line_subtotal_tax)) {
            $line_subtotal_tax = 0;
        }

        // Get all order tax rows if we don't have them yet
        if (is_null($this->order_tax)) {
            $this->order_tax = $this->get_order_tax();
        }

        // Calculate current tax rate from subtotal and subtotal tax
        $tax_rate = $line_subtotal_tax / $line_subtotal * 100;

        // Due to rounding we may have incorrect tax rate - attempt to fix by matching with tax rates from DB
        $tax_rate_fixed = $tax_rate;
        $tax_rate_fix_amount = 0;

        foreach ($this->order_tax as $tax) {
            if (!isset($tax['woo_pdf_tax_rate'])) {
                continue;
            }

            if (($tax['woo_pdf_tax_rate'] > $tax_rate && $tax['woo_pdf_tax_rate'] < ($tax_rate + 0.49)) || ($tax['woo_pdf_tax_rate'] < $tax_rate && $tax['woo_pdf_tax_rate'] > ($tax_rate - 0.49))) {
                if ($tax_rate_fix_amount == 0 || abs($tax['woo_pdf_tax_rate'] - $tax_rate) < $tax_rate_fix_amount) {
                    $tax_rate_fixed = $tax['woo_pdf_tax_rate'];
                    $tax_rate_fix_amount = abs($tax['woo_pdf_tax_rate'] - $tax_rate);
                }
            }
        }

        return round((float) $tax_rate_fixed, 2);
    }

    /**
     * Get order tax and tax rates
     *
     * @access public
     * @return array
     */
    public function get_order_tax()
    {
        // Get tax rows
        $tax = $this->orderData->get_items('tax');

        if (!is_array($tax) || empty($tax)) {
            return array();
        }

        global $wpdb;

        $all_rates = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'woocommerce_tax_rates');

        if (!is_array($all_rates) || empty($all_rates)) {
            return $tax;
        }

        foreach ($tax as $single_tax_key => $single_tax) {
            foreach ($all_rates as $rate) {
                if (isset($single_tax['rate_id']) && $single_tax['rate_id'] == $rate->tax_rate_id) {
                    $tax[$single_tax_key]['woo_pdf_tax_rate'] = $rate->tax_rate;
                }
            }
        }

        return $tax;
    }

    /**
     * Replace macros in text
     *
     * @access public
     * @param string $string
     * @param bool $remove_empty_lines
     * @return string
     */
    public function replace_macros($string, $remove_empty_lines = false)
    {
        // List supported macros and get their values
        $macros = array(
            '{{order_id}}'              => $this->orderData->get_order_number(),
            '{{order_date}}'            => $this->date(true),
            '{{customer_id}}'           => $this->orderData->customer_user,
            '{{customer_note}}'         => $this->orderData->customer_note,
            '{{payment_method}}'        => $this->orderData->payment_method_title,
            '{{shipping_method}}'       => $this->orderData->get_shipping_method(),
            '{{billing_first_name}}'    => $this->orderData->billing_first_name,
            '{{billing_last_name}}'     => $this->orderData->billing_last_name,
            '{{billing_company}}'       => $this->orderData->billing_company,
            '{{billing_address_1}}'     => $this->orderData->billing_address_1,
            '{{billing_address_2}}'     => $this->orderData->billing_address_2,
            '{{billing_city}}'          => $this->orderData->billing_city,
            '{{billing_postcode}}'      => $this->orderData->billing_postcode,
            '{{billing_country}}'       => $this->orderData->billing_country,
            '{{billing_state}}'         => $this->orderData->billing_state,
            '{{billing_email}}'         => $this->orderData->billing_email,
            '{{billing_phone}}'         => $this->orderData->billing_phone,
            '{{shipping_first_name}}'   => $this->orderData->shipping_first_name,
            '{{shipping_last_name}}'    => $this->orderData->shipping_last_name,
            '{{shipping_company}}'      => $this->orderData->shipping_company,
            '{{shipping_address_1}}'    => $this->orderData->shipping_address_1,
            '{{shipping_address_2}}'    => $this->orderData->shipping_address_2,
            '{{shipping_city}}'         => $this->orderData->shipping_city,
            '{{shipping_postcode}}'     => $this->orderData->shipping_postcode,
            '{{shipping_country}}'      => $this->orderData->shipping_country,
            '{{shipping_state}}'        => $this->orderData->shipping_state,
            '{{woo_pdf_invoice_id}}'    => (!empty($this->invoiceInfo['prefix']) ? $this->decode($this->invoiceInfo['prefix']) . ' ' : '') . $this->invoiceInfo['id'] . (!empty($this->invoiceInfo['suffix']) ? ' ' . $this->decode($this->invoiceInfo['suffix']) : ''),
            '{{invoice_number}}'        => (!empty($this->invoiceInfo['prefix']) ? $this->decode($this->invoiceInfo['prefix']) . ' ' : '') . $this->invoiceInfo['id'] . (!empty($this->invoiceInfo['suffix']) ? ' ' . $this->decode($this->invoiceInfo['suffix']) : ''),
            '{{invoice_date}}'          => $this->date(),
            '{{order_currency}}'        => $this->decode($this->currency(false)),
            '{{order_amount}}'          => $this->format_currency($this->total()),
            '{{invoice_title}}'         => $this->decode($this->invoiceType == 'invoice' ? $this->invoiceOptions['woo_pdf_document_name'] : $this->invoiceOptions['woo_pdf_proforma_name']),
            '{{invoice_date_title}}'    => $this->decode($this->invoiceOptions['woo_pdf_title_date']),
            '{{order_amount_title}}'    =>  $this->decode($this->invoiceOptions['woo_pdf_title_amount']),
        );

        // Allow custom macros
        $macros = apply_filters('woo_pdf_macros', $macros, $this->orderData);

        // Split entire block into array line by line
        $lines = explode(PHP_EOL, $string);
        $processed_lines = array();

        // Load WC countries to replace country macros
        if (!$this->wc_countries) {
            $this->wc_countries = new WC_Countries();
        }

        // Iterate over all lines and replace macros
        foreach ($lines as $line_key => $line) {

            $macros_found = 0;
            $macros_not_empty = 0;
            $processed_line = $line;

            // Search for regular macros and replace them
            foreach ($macros as $macro => $value) {

                $replace_count = 0;

                // Convert two-letter country code to full country name
                if (in_array($macro, array('{{billing_country}}', '{{shipping_country}}')) && $this->wc_countries && isset($this->wc_countries->countries[$value])) {

                    // Also maybe save the states
                    if (isset($this->wc_countries->states[$value])) {
                        $states = $this->wc_countries->states[$value];
                    }

                    $value = $this->wc_countries->countries[$value];
                }

                // Convert state code to full state name
                if (in_array($macro, array('{{billing_state}}', '{{shipping_state}}')) && isset($states[$value])) {
                    $value = $states[$value];
                }

                $processed_line = preg_replace('/'.$macro.'/', preg_replace('/\$/', '\\\$', $value), $processed_line, -1, $replace_count);

                $macros_found = $macros_found + $replace_count;

                if ($value != '') {
                    $macros_not_empty = $macros_not_empty + $replace_count;
                }
            }

            // Search for custom macros and attempt to replace them
            preg_match_all('/\{\{.+(?:\}\})/U', $processed_line, $matches);

            if (!empty($matches) && !empty($matches[0])) {
                foreach ($matches[0] as $match) {

                    $key = preg_replace('/\{\{(.+)\}\}/', '$1', $match);

                    // Check in standard custom fields
                    if (isset($this->orderData->order_custom_fields[$key]) || isset($this->orderData->order_custom_fields['_'.$key])) {

                        $key = isset($this->orderData->order_custom_fields[$key]) ? $key : '_' . $key;

                        $macros_found++;

                        $value = is_array($this->orderData->order_custom_fields[$key]) ? $this->orderData->order_custom_fields[$key][0] : $this->orderData->order_custom_fields[$key];

                        if ($value != '') {
                            $macros_not_empty++;
                        }

                        $processed_line = preg_replace('/'.$match.'/', preg_replace('/\$/', '\\\$', $value), $processed_line);
                    }

                    // Check in order post meta
                    else {
                        $buyer_custom_field = get_post_meta($this->orderData->id, $key, true);

                        if ($buyer_custom_field == '') {
                            $buyer_custom_field = get_post_meta($this->orderData->id, '_'.$key, true);
                        }

                        if ($buyer_custom_field != '') {
                            $macros_found++;
                            $macros_not_empty++;
                            $processed_line = preg_replace('/'.$match.'/', preg_replace('/\$/', '\\\$', $buyer_custom_field), $processed_line);
                        }
                    }
                }
            }

            if (!$remove_empty_lines || $macros_found == 0 || $macros_not_empty != 0) {
                $processed_lines[] = $processed_line;
            }
        }

        // Join lines
        $string = join(PHP_EOL, $processed_lines);

        // Remove all macros that were not found
        $string = preg_replace('/\{\{.+(?:\}\})/U', '', $string);

        return $string;
    }

    /**
     * Return currency symbol
     *
     * @param bool $symbol
     * @return string
     */
    public function currency($symbol = true)
    {
        $currencies_symbols_list = array(
            'ALL' => 'Lek',
            'AFN' => 'Ø',
            'ARS' => '$',
            'AWG' => 'Æ',
            'AUD' => '$',
            'AZN' => 'Ð¼Ð°Ð½',
            'BSD' => '$',
            'BBD' => '$',
            'BYR' => 'p.',
            'BZD' => 'BZ$',
            'BMD' => '$',
            'BOB' => '$b',
            'BAM' => 'KM',
            'BWP' => 'P',
            'BGN' => 'Ð»Ð²',
            'BRL' => 'R$',
            'BND' => '$',
            'KHR' => 'á',
            'CAD' => '$',
            'KYD' => '$',
            'CLP' => '$',
            'CNY' => 'Â¥',
            'COP' => '$',
            'CRC' => 'â¡',
            'HRK' => 'kn',
            'CUP' => 'â±',
            'CZK' => 'KÄ',
            'DKK' => 'kr',
            'DOP' => 'RD$',
            'XCD' => '$',
            'EGP' => 'Â£',
            'SVC' => '$',
            'EEK' => 'kr',
            'EUR' => 'â¬',
            'FKP' => 'Â£',
            'FJD' => '$',
            'GHC' => 'Â¢',
            'GIP' => 'Â£',
            'GTQ' => 'Q',
            'GGP' => 'Â£',
            'GYD' => '$',
            'HNL' => 'L',
            'HKD' => '$',
            'HUF' => 'Ft',
            'ISK' => 'kr',
            'INR' => 'â¹',
            'IDR' => 'Rp',
            'IRR' => 'ï·¼',
            'IMP' => 'Â£',
            'ILS' => 'âª',
            'JMD' => 'J$',
            'JPY' => 'Â¥',
            'JEP' => 'Â£',
            'KZT' => 'Ð»Ð²',
            'KPW' => 'â©',
            'KRW' => 'â©',
            'KGS' => 'Ð»Ð²',
            'LAK' => 'â­',
            'LVL' => 'Ls',
            'LBP' => 'Â£',
            'LRD' => '$',
            'LTL' => 'Lt',
            'MKD' => 'Ð´ÐµÐ½',
            'MYR' => 'RM',
            'MUR' => 'â¨',
            'MXN' => '$',
            'MNT' => 'â®',
            'MZN' => 'MT',
            'NAD' => 'N$',
            'NPR' => 'â¨',
            'ANG' => 'Æ',
            'NZD' => '$',
            'NIO' => 'C$',
            'NGN' => 'â¦',
            'KPW' => 'â©',
            'NOK' => 'kr',
            'OMR' => 'ï·¼',
            'PKR' => 'â¨',
            'PAB' => 'B/.',
            'PYG' => 'Gs',
            'PEN' => 'S/.',
            'PHP' => 'â±',
            'PLN' => 'zÅ',
            'QAR' => 'ï·¼',
            'RON' => 'lei',
            'RUB' => 'ÑÑÐ±',
            'SHP' => 'Â£',
            'SAR' => 'ï·¼',
            'RSD' => 'ÐÐ¸Ð½.',
            'SCR' => 'â¨',
            'SGD' => '$',
            'SBD' => '$',
            'SOS' => 'S',
            'ZAR' => 'R',
            'KRW' => 'â©',
            'LKR' => 'â¨',
            'SEK' => 'kr',
            'CHF' => 'CHF',
            'SRD' => '$',
            'SYP' => 'Â£',
            'TWD' => 'NT$',
            'THB' => 'à¸¿',
            'TTD' => 'TT$',
            'TRY' => 'TL',
            'TRL' => 'â¤',
            'TVD' => '$',
            'UAH' => 'â´',
            'GBP' => 'Â£',
            'USD' => '$',
            'UYU' => '$U',
            'UZS' => 'Ð»Ð²',
            'VEF' => 'Bs',
            'VND' => 'â«',
            'YER' => 'ï·¼',
            'ZWD' => 'Z$',
        );

        // Try to use order currency, or use default currency instead
        $order_currency_code = $this->orderData->get_order_currency();
        $currency_code = !empty($order_currency_code) ? $order_currency_code : get_woocommerce_currency();

        if ($symbol) {
            if (isset($currencies_symbols_list[$currency_code])) {
                return $currencies_symbols_list[$currency_code];
            }
            else {
                return false;
            }
        }
        else {
            return $currency_code;
        }
    }

    /**
     * Get currency name
     *
     * @param int $number
     * @return string
     */
    public function get_currency_name($number)
    {
        $dictionary  = array(
            'AED' => _n('dirham', 'dirhams', $number, 'woo_pdf'),
            'ARS' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'AUD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'BDT' => _n('taka', 'taka', $number, 'woo_pdf'),
            'BRL' => _n('real', 'reais', $number, 'woo_pdf'),
            'BGN' => _n('lev', 'leva', $number, 'woo_pdf'),
            'CAD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'CLP' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'CNY' => _n('yuan', 'yuan', $number, 'woo_pdf'),
            'COP' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'CZK' => _n('koruna', 'koruna', $number, 'woo_pdf'),
            'DKK' => _n('krone', 'kroner', $number, 'woo_pdf'),
            'DOP' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'EUR' => _n('euro', 'euros', $number, 'woo_pdf'),
            'HKD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'HRK' => _n('kuna', 'kuna', $number, 'woo_pdf'),
            'HUF' => _n('forint', 'forint', $number, 'woo_pdf'),
            'ISK' => _n('krona', 'kronur', $number, 'woo_pdf'),
            'IDR' => _n('rupiah', 'rupiahs', $number, 'woo_pdf'),
            'INR' => _n('rupee', 'rupees', $number, 'woo_pdf'),
            'NPR' => _n('rupee', 'rupees', $number, 'woo_pdf'),
            'ILS' => _n('shekel', 'shekalim', $number, 'woo_pdf'),
            'JPY' => _n('yen', 'yen', $number, 'woo_pdf'),
            'KIP' => _n('kip', 'kip', $number, 'woo_pdf'),
            'KRW' => _n('won', 'won', $number, 'woo_pdf'),
            'MYR' => _n('ringgit', 'ringgit', $number, 'woo_pdf'),
            'MXN' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'NGN' => _n('naira', 'naira', $number, 'woo_pdf'),
            'NOK' => _n('krone', 'kroner', $number, 'woo_pdf'),
            'NZD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'PYG' => _n('guarani', 'guaranies', $number, 'woo_pdf'),
            'PHP' => _n('peso', 'pesos', $number, 'woo_pdf'),
            'PLN' => _n('zloty', 'zloty', $number, 'woo_pdf'),
            'GBP' => _n('pound', 'pounds', $number, 'woo_pdf'),
            'RON' => _n('leu', 'lei', $number, 'woo_pdf'),
            'RUB' => _n('ruble', 'rublei', $number, 'woo_pdf'),
            'SGD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'ZAR' => _n('rand', 'rand', $number, 'woo_pdf'),
            'SEK' => _n('krona', 'kronor', $number, 'woo_pdf'),
            'CHF' => _n('franc', 'francs', $number, 'woo_pdf'),
            'TWD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'THB' => _n('baht', 'baht', $number, 'woo_pdf'),
            'TRY' => _n('lira', 'liras', $number, 'woo_pdf'),
            'UAH' => _n('hryvnia', 'hryvni', $number, 'woo_pdf'),
            'USD' => _n('dollar', 'dollars', $number, 'woo_pdf'),
            'VND' => _n('dong', 'dong', $number, 'woo_pdf'),
            'EGP' => _n('pound', 'pounds', $number, 'woo_pdf')
        );

        // Try to use order currency, or use default currency instead
        $order_currency_code = $this->orderData->get_order_currency();
        $currency_code = !empty($order_currency_code) ? $order_currency_code : get_woocommerce_currency();

        if (isset($dictionary[$currency_code])) {
            return $dictionary[$currency_code];
        }

        return false;
    }

    /**
     * Get currency fraction name
     *
     * @param int $number
     * @return string
     */
    public function get_currency_fraction_name($number)
    {
        $dictionary  = array(
            'AED' => _n('fils', 'fulÅ«s', $number, 'woo_pdf'),
            'ARS' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'AUD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'BDT' => _n('paisa', 'paisa', $number, 'woo_pdf'),
            'BRL' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'BGN' => _n('stotinka', 'stotinki', $number, 'woo_pdf'),
            'CAD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'CLP' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'CNY' => _n('fen', 'fen', $number, 'woo_pdf'),
            'COP' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'CZK' => _n('heller', 'heller', $number, 'woo_pdf'),
            'DKK' => _n('Ã¸re', 'Ã¸re', $number, 'woo_pdf'),
            'DOP' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'EUR' => _n('cent', 'cents', $number, 'woo_pdf'),
            'HKD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'HRK' => _n('lipa', 'lipa', $number, 'woo_pdf'),
            'HUF' => _n('fillÃ©r', 'fillÃ©r', $number, 'woo_pdf'),
            'ISK' => _n('eyrir', 'aurar', $number, 'woo_pdf'),
            'IDR' => _n('sen', 'sen', $number, 'woo_pdf'),
            'INR' => _n('paisa ', 'paisa', $number, 'woo_pdf'),
            'NPR' => _n('paisa ', 'paisa', $number, 'woo_pdf'),
            'ILS' => _n('agora ', 'agorot', $number, 'woo_pdf'),
            'JPY' => _n('sen', 'sen', $number, 'woo_pdf'),
            'KIP' => _n('att', 'att', $number, 'woo_pdf'),
            'KRW' => _n('jeon', 'jeon', $number, 'woo_pdf'),
            'MYR' => _n('sen', 'sen', $number, 'woo_pdf'),
            'MXN' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'NGN' => _n('kobo', 'kobo', $number, 'woo_pdf'),
            'NOK' => _n('Ã¸re', 'Ã¸re', $number, 'woo_pdf'),
            'NZD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'PYG' => _n('cÃ©ntimo', 'cÃ©ntimos', $number, 'woo_pdf'),
            'PHP' => _n('centavo', 'centavos', $number, 'woo_pdf'),
            'PLN' => _n('grosz', 'grosz', $number, 'woo_pdf'),
            'GBP' => _n('penny ', 'pennies', $number, 'woo_pdf'),
            'RON' => _n('ban', 'bani', $number, 'woo_pdf'),
            'RUB' => _n('kopeyka', 'kopeyek', $number, 'woo_pdf'),
            'SGD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'ZAR' => _n('cent', 'cents', $number, 'woo_pdf'),
            'SEK' => _n('Ã¶re', 'Ã¶re', $number, 'woo_pdf'),
            'CHF' => _n('rappen', 'rappen', $number, 'woo_pdf'),
            'TWD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'THB' => _n('satang', 'satang', $number, 'woo_pdf'),
            'TRY' => _n('kuruÅ', 'kuruÅ', $number, 'woo_pdf'),
            'UAH' => _n('kopiyka', 'kopiyky', $number, 'woo_pdf'),
            'USD' => _n('cent', 'cents', $number, 'woo_pdf'),
            'VND' => _n('hÃ o', 'hÃ o', $number, 'woo_pdf'),
            'EGP' => _n('piastre', 'piastres', $number, 'woo_pdf')
        );

        // Try to use order currency, or use default currency instead
        $order_currency_code = $this->orderData->get_order_currency();
        $currency_code = !empty($order_currency_code) ? $order_currency_code : get_woocommerce_currency();

        if (isset($dictionary[$currency_code])) {
            return $dictionary[$currency_code];
        }

        return false;
    }

    /**
     * Return capitalized amount in words
     *
     * @param int $number
     * @return string
     */
    public function get_amount_in_words($number)
    {
        return ucfirst($this->amount_in_words($number));
    }

    /**
     * Convert amount to amount in words
     * Based on: http://www.karlrixon.co.uk/writing/convert-numbers-to-words-with-php/
     *
     * @param int $number
     * @param bool $self
     * @return string
     */
    public function amount_in_words($number, $self = false)
    {
        $hyphens = array('0' => '-', '1' => ' ', '2' => '');
        $hyphen = _x('-', 'separator for numbers 21 through 99 (amount in words)', 'woo_pdf');

        $conjunction = ' ' . __('and', 'woo_pdf') . ' ';

        $dictionary  = array(
            0 => __('zero', 'woo_pdf'),
            1 => __('one', 'woo_pdf'),
            2 => __('two', 'woo_pdf'),
            3 => __('three', 'woo_pdf'),
            4 => __('four', 'woo_pdf'),
            5 => __('five', 'woo_pdf'),
            6 => __('six', 'woo_pdf'),
            7 => __('seven', 'woo_pdf'),
            8 => __('eight', 'woo_pdf'),
            9 => __('nine', 'woo_pdf'),
            10 => __('ten', 'woo_pdf'),
            11 => __('eleven', 'woo_pdf'),
            12 => __('twelve', 'woo_pdf'),
            13 => __('thirteen', 'woo_pdf'),
            14 => __('fourteen', 'woo_pdf'),
            15 => __('fifteen', 'woo_pdf'),
            16 => __('sixteen', 'woo_pdf'),
            17 => __('seventeen', 'woo_pdf'),
            18 => __('eighteen', 'woo_pdf'),
            19 => __('nineteen', 'woo_pdf'),
            20 => __('twenty', 'woo_pdf'),
            30 => __('thirty', 'woo_pdf'),
            40 => __('fourty', 'woo_pdf'),
            50 => __('fifty', 'woo_pdf'),
            60 => __('sixty', 'woo_pdf'),
            70 => __('seventy', 'woo_pdf'),
            80 => __('eighty', 'woo_pdf'),
            90 => __('ninety', 'woo_pdf'),
            100 => __('hundred', 'woo_pdf'),
            1000 => __('thousand', 'woo_pdf'),
            1000000 => __('million', 'woo_pdf'),
            1000000000 => __('billion', 'woo_pdf'),
            1000000000000 => __('trillion', 'woo_pdf'),
            1000000000000000 => __('quadrillion', 'woo_pdf'),
            1000000000000000000 => __('quintillion', 'woo_pdf')
        );

        if (!is_numeric($number)) {
            return $self ? $dictionary[0] : false;
        }
        if (($number >= 0 && (int) $number < 0) || (int) $number < 0 || ($self && empty($number))) {
            return $self ? $dictionary[0] : false;
        }

        // Make sure we have at least two digits for cents
        if (!$self) {
            $number = number_format((float)$number, absint(get_option('woocommerce_price_num_decimals')), '.', '');
        }

        $string = $fraction = null;

        // Accept both decimal separators
        if (strpos($number, '.') !== false) {
            list($number, $fraction) = explode('.', $number);
        }
        else if (strpos($number, ',') !== false) {
            list($number, $fraction) = explode(',', $number);
        }

        switch (true) {
            case $number < 21:
                $string = $dictionary[$number];
                break;
            case $number < 100:
                $tens   = ((int) ($number / 10)) * 10;
                $units  = $number % 10;
                $string = $dictionary[$tens];
                if ($units) {
                    $string .= $hyphen . $dictionary[$units];
                }
                break;
            case $number < 1000:
                $hundreds  = $number / 100;
                $remainder = $number % 100;
                $string = $dictionary[$hundreds] . ' ' . $dictionary[100];
                if ($remainder) {
                    $string .= ' ' . $this->amount_in_words($remainder, true);
                }
                break;
            default:
                $baseUnit = pow(1000, floor(log($number, 1000)));
                $numBaseUnits = (int) ($number / $baseUnit);
                $remainder = $number % $baseUnit;
                $string = $this->amount_in_words($numBaseUnits, true) . ' ' . $dictionary[$baseUnit];
                if ($remainder) {
                    //$string .= $remainder < 100 ? $conjunction : $separator;
                    $string .= ' ' . $this->amount_in_words($remainder, true);
                }
                break;
        }

        if (!$self) {
            $string .= ' ';
            //$string .= _n('dollar', 'dollars', $number, 'woo_pdf');
            $string .= $this->get_currency_name($number);
        }

        if ($fraction !== null && is_numeric($fraction)) {
            $string .= $conjunction;
            $string .= $this->amount_in_words(ltrim($fraction, '0'), true);
            //$string .= $fraction;
            $string .= ' ';
            //$string .= _n('cent', 'cents', $fraction, 'woo_pdf');
            $string .= $this->get_currency_fraction_name($fraction);
        }

        return $string;
    }

    /**
     * Get product categories
     *
     * @access public
     * @param array $product_id
     * @return mixed
     */
    public function get_product_category_names($product_id)
    {
        $categories = array();
        $current_categories = wp_get_post_terms($product_id, 'product_cat');

        $post_categories_raw = null;

        foreach ($current_categories as $category) {
            $category_name = $category->name;

            if ($category->parent) {

                if (is_null($post_categories_raw)) {
                    $post_categories_raw = get_terms(array('product_cat'), array('hide_empty' => 0));
                }

                $parent_id = $category->parent;
                $has_parent = true;

                while ($has_parent) {
                    foreach ($post_categories_raw as $parent_post_cat_key => $parent_post_cat) {
                        if ($parent_post_cat->term_id == $parent_id) {
                            $category_name = $parent_post_cat->name . ' &rarr; ' . $category_name;

                            if ($parent_post_cat->parent) {
                                $parent_id = $parent_post_cat->parent;
                            }
                            else {
                                $has_parent = false;
                            }

                            break;
                        }
                    }
                }
            }

            $categories[$category->term_id] = $category_name;
        }

        return !empty($categories) ? $categories : false;
    }

    /**
     * Get correctly rounded and formatted price with enough decimals
     *
     * @access public
     * @param float $price
     * @return string
     */
    public function get_rounded_price($price, $quantity)
    {
        // Get absolute maximum decimal number
        $min_decimals = get_option('woocommerce_price_num_decimals');
        $max_decimals = $min_decimals + strlen((string) $quantity);

        // Initial decimals check
        $actual_decimals = strlen(substr(strrchr($price, '.'), 1));
        $price_decimals = $actual_decimals > $max_decimals ? $max_decimals : $actual_decimals;

        // Trim price to the specific amount of decimals and remove trailing zeros
        $factor = (float) '1' . str_repeat('0', $price_decimals);
        $price = floor((float) $price * $factor) / $factor;

        // Second decimals check (in case we don't need all the decimal places because of trailing zeros that got removed)
        $actual_decimals = strlen(substr(strrchr($price, '.'), 1));
        $price_decimals = $actual_decimals < $min_decimals ? $min_decimals : $actual_decimals;

        // Format and return
        return $this->format_currency(number_format($price, $price_decimals, get_option('woocommerce_price_decimal_sep'), get_option('woocommerce_price_thousand_sep')));
    }


}

}

?>