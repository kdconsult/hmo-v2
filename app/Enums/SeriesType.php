<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SeriesType: string implements HasLabel
{
    case Quote = 'quote';
    case SalesOrder = 'sales_order';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case ProformaInvoice = 'proforma_invoice';
    case DeliveryNote = 'delivery_note';
    case PurchaseOrder = 'purchase_order';
    case SupplierInvoice = 'supplier_invoice';
    case SupplierCreditNote = 'supplier_credit_note';
    case GoodsReceivedNote = 'goods_received_note';
    case InternalConsumptionNote = 'internal_consumption_note';
    case Product = 'product';
    case Partner = 'partner';

    public function getLabel(): string
    {
        return match ($this) {
            self::Quote => __('Quote'),
            self::SalesOrder => __('Sales Order'),
            self::Invoice => __('Invoice'),
            self::CreditNote => __('Credit Note'),
            self::DebitNote => __('Debit Note'),
            self::ProformaInvoice => __('Proforma Invoice'),
            self::DeliveryNote => __('Delivery Note'),
            self::PurchaseOrder => __('Purchase Order'),
            self::SupplierInvoice => __('Supplier Invoice'),
            self::SupplierCreditNote => __('Supplier Credit Note'),
            self::GoodsReceivedNote => __('Goods Received Note'),
            self::InternalConsumptionNote => __('Internal Consumption Note'),
            self::Product => __('Product Code'),
            self::Partner => __('Partner Code'),
        };
    }
}
