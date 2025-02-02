<?php

namespace OFFLINE\Mall\Classes\Totals;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use OFFLINE\Mall\Classes\Cart\DiscountApplier;
use OFFLINE\Mall\Models\CartProduct;
use OFFLINE\Mall\Models\Discount;
use OFFLINE\Mall\Models\Tax;
use OFFLINE\Mall\Models\WishlistItem;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class TotalsCalculator
{
    /**
     * @var TotalsCalculatorInput
     */
    protected $input;
    /**
     * @var Collection<TaxTotal>
     */
    protected $taxes;
    /**
     * @var Collection<TaxTotal>
     */
    protected $detailedTaxes;
    /**
     * @var ShippingTotal
     */
    protected $shippingTotal;
    /**
     * @var int
     */
    protected $weightTotal;
    /**
     * @var int
     */
    protected $totalPreTaxes;
    /**
     * @var int
     */
    protected $totalPostTaxes;
    /**
     * @var int
     */
    protected $totalTaxes;
    /**
     * @var int
     */
    protected $totalDiscounts;
    /**
     * @var int
     */
    protected $productPreTaxes;
    /**
     * @var int
     */
    protected $productTaxes;
    /**
     * @var int
     */
    protected $productPostTaxes;
    /**
     * @var Collection
     */
    protected $appliedDiscounts;
    /**
     * @var Collection
     */
    public $shippingTaxes;
    /**
     * @var int
     */
    protected $totalPrePayment;
    /**
     * @var PaymentTotal
     */
    protected $paymentTotal;
    /**
     * @var Collection
     */
    public $paymentTaxes;

    public function __construct(TotalsCalculatorInput $input)
    {
        $this->input = $input;
        $this->taxes = new Collection();

        $this->calculate();
    }

    protected function calculate()
    {
        $this->weightTotal      = $this->calculateWeightTotal();
        $this->productPreTaxes  = $this->calculateProductPreTaxes();
        $this->productTaxes     = $this->calculateProductTaxes();
        $this->productPostTaxes = $this->productPreTaxes + $this->productTaxes;

        $this->shippingTaxes = $this->filterShippingTaxes();
        $this->shippingTotal = new ShippingTotal($this->input->shipping_method, $this);
        $this->totalPreTaxes = $this->productPreTaxes + $this->shippingTotal->totalPreTaxes();

        $this->totalDiscounts = $this->productPostTaxes - $this->applyTotalDiscounts($this->productPostTaxes);

        $this->totalPrePayment = $this->productPostTaxes - $this->totalDiscounts + $this->shippingTotal->totalPostTaxes();
        $this->paymentTaxes    = $this->filterPaymentTaxes();
        $this->paymentTotal    = new PaymentTotal($this->input->payment_method, $this);

        $this->totalPostTaxes = $this->totalPrePayment + $this->paymentTotal->totalPostTaxes();

        $this->taxes = $this->getTaxTotals();
    }

    protected function calculateProductPreTaxes(): float
    {
        $total = $this->input->products->sum('totalPreTaxes');

        return $total > 0 ? $total : 0;
    }

    protected function calculateProductTaxes(): float
    {
        return $this->input->products->sum('totalTaxes');
    }

    protected function getTaxTotals(): Collection
    {
        $shippingTaxes = new Collection();
        $shippingTotal = $this->shippingTotal->totalPreTaxesOriginal();
        if ($this->shippingTaxes) {
            $shippingTaxes = $this->shippingTaxes->map(function (Tax $tax) use ($shippingTotal) {
                return new TaxTotal($shippingTotal, $tax);
            });
        }

        $paymentTaxes = new Collection();
        $paymentTotal = $this->paymentTotal->totalPreTaxesOriginal();
        if ($this->paymentTaxes) {
            $paymentTaxes = $this->paymentTaxes->map(function (Tax $tax) use ($paymentTotal) {
                return new TaxTotal($paymentTotal, $tax);
            });
        }

        /** @var $product CartProduct|WishlistItem */
        $productTaxes = $this->input->products->flatMap(function ($product) {
            return $product->filtered_taxes->map(function (Tax $tax) use ($product) {
                return new TaxTotal($product->totalPreTaxes, $tax);
            });
        });

        $combined = $productTaxes->concat($shippingTaxes)->concat($paymentTaxes);

        $this->totalTaxes = $combined->sum(function (TaxTotal $tax) {
            return $tax->total();
        });

        $this->detailedTaxes = $combined;

        return $this->consolidateTaxes($combined);
    }

    /**
     * This method consolidates the same taxes on shipping
     * and products down to one combined TaxTotal.
     */
    protected function consolidateTaxes(Collection $taxTotals)
    {
        return $taxTotals->groupBy(function (TaxTotal $taxTotal) {
            return $taxTotal->tax->id;
        })->map(function (Collection $grouped) {
            $tax    = $grouped->first()->tax;
            $preTax = $grouped->sum(function (TaxTotal $tax) {
                return $tax->preTax();
            });

            return new TaxTotal($preTax, $tax);
        })->values();
    }

    protected function calculateWeightTotal(): int
    {
        return $this->input->products->sum(function ($product) {
            return $product->weight * $product->quantity;
        });
    }

    public function paymentTotal(): PaymentTotal
    {
        return $this->paymentTotal;
    }

    public function shippingTotal(): ShippingTotal
    {
        return $this->shippingTotal;
    }

    public function weightTotal(): int
    {
        return $this->weightTotal;
    }

    public function totalPreTaxes(): float
    {
        return $this->totalPreTaxes;
    }

    public function totalTaxes(): float
    {
        return $this->totalTaxes;
    }

    public function totalPrePayment(): float
    {
        return $this->totalPrePayment;
    }

    public function productPreTaxes(): float
    {
        return $this->productPreTaxes;
    }

    public function productTaxes(): float
    {
        return $this->productTaxes;
    }

    public function productPostTaxes(): float
    {
        return $this->productPostTaxes;
    }

    public function totalPostTaxes(): float
    {
        return $this->totalPostTaxes;
    }

    public function taxes(bool $detailed = false): Collection
    {
        return $detailed ? $this->detailedTaxes : $this->taxes;
    }

    public function detailedTaxes(): Collection
    {
        return $this->taxes(true);
    }

    public function getInput(): TotalsCalculatorInput
    {
        return $this->input;
    }

    public function appliedDiscounts(): Collection
    {
        return $this->appliedDiscounts;
    }

    /**
     * Process the discounts that are applied to the cart's total.
     */
    protected function applyTotalDiscounts($total): ?float
    {
        $nonCodeTriggers = Discount::whereIn('trigger', ['total', 'product'])
                                   ->where(function ($q) {
                                       $q->whereNull('expires')
                                         ->orWhere('expires', '>', Carbon::now());
                                   })->get();

        $discounts = $this->input->discounts->merge($nonCodeTriggers)->reject(function ($item) {
            return $item->type === 'shipping';
        });

        $applier                = new DiscountApplier($this->input, $total);
        $this->appliedDiscounts = $applier->applyMany($discounts);

        return $applier->reducedTotal();
    }

    /**
     * Filter out shipping taxes that have to be applied for
     * the current shipping address of the cart.
     *
     * @return Collection
     */
    protected function filterShippingTaxes()
    {
        $taxes = optional($this->input->shipping_method)->taxes ?? new Collection();

        return $taxes->filter(function (Tax $tax) {
            // If no shipping address is available only include taxes that have no country restrictions.
            if ($this->input->shipping_country_id === null) {
                return $tax->countries->count() === 0;
            }

            return $tax->countries->count() === 0
                || $tax->countries->pluck('id')->search($this->input->shipping_country_id) !== false;
        });
    }

    /**
     * Filter out payment taxes that have to be applied for
     * the current shipping address of the cart.
     *
     * @return Collection
     */
    protected function filterPaymentTaxes()
    {
        $taxes = optional($this->input->payment_method)->taxes ?? new Collection();

        return $taxes->filter(function (Tax $tax) {
            // If no shipping address is available only include taxes that have no country restrictions.
            if ($this->input->shipping_country_id === null) {
                return $tax->countries->count() === 0;
            }

            return $tax->countries->count() === 0
                || $tax->countries->pluck('id')->search($this->input->shipping_country_id) !== false;
        });
    }
}
