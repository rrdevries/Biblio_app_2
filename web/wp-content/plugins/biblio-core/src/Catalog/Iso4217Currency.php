<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Iso4217Currency
{
    /**
     * Version 2026-09. Active ISO 4217 codes plus retained historical codes
     * needed for exact acquisition-price history.
     */
    private const array ALLOWED = [
        "AED", "AFN", "ALL", "AMD", "ANG", "AOA", "ARS", "AUD", "AWG", "AZN",
        "BAM", "BBD", "BDT", "BGN", "BHD", "BIF", "BMD", "BND", "BOB", "BOV",
        "BRL", "BSD", "BTN", "BWP", "BYN", "BZD", "CAD", "CDF", "CHE", "CHF",
        "CHW", "CLF", "CLP", "CNY", "COP", "COU", "CRC", "CUC", "CUP", "CVE",
        "CZK", "DJF", "DKK", "DOP", "DZD", "EGP", "ERN", "ETB", "EUR", "FJD",
        "FKP", "GBP", "GEL", "GHS", "GIP", "GMD", "GNF", "GTQ", "GYD", "HKD",
        "HNL", "HRK", "HTG", "HUF", "IDR", "ILS", "INR", "IQD", "IRR", "ISK",
        "JMD", "JOD", "JPY", "KES", "KGS", "KHR", "KMF", "KPW", "KRW", "KWD",
        "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL", "LYD", "MAD", "MDL",
        "MGA", "MKD", "MMK", "MNT", "MOP", "MRU", "MUR", "MVR", "MWK", "MXN",
        "MXV", "MYR", "MZN", "NAD", "NGN", "NIO", "NOK", "NPR", "NZD", "OMR",
        "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG", "QAR", "RON", "RSD",
        "RUB", "RWF", "SAR", "SBD", "SCR", "SDG", "SEK", "SGD", "SHP", "SLE",
        "SLL", "SOS", "SRD", "SSP", "STN", "SVC", "SYP", "SZL", "THB", "TJS",
        "TMT", "TND", "TOP", "TRY", "TTD", "TWD", "TZS", "UAH", "UGX", "USD",
        "USN", "UYI", "UYU", "UYW", "UZS", "VED", "VES", "VND", "VUV", "WST",
        "XAF", "XAG", "XAU", "XBA", "XBB", "XBC", "XBD", "XCD", "XDR", "XOF",
        "XPD", "XPF", "XPT", "XSU", "XTS", "XUA", "XXX", "YER", "ZAR", "ZMW",
        "ZWL", "ADP", "ATS", "BEF", "BGL", "BYB", "CYP", "DEM", "EEK", "ESP",
        "FIM", "FRF", "GRD", "IEP", "ITL", "LTL", "LUF", "LVL", "MTL", "NLG",
        "PTE", "ROL", "RUR", "SDD", "SIT", "SKK", "TRL", "VEB", "XEU", "YUM",
        "ZMK", "ZWD",
    ];

    public function __construct(private string $code)
    {
        if (!in_array($this->code, self::ALLOWED, true)) {
            throw new ValidationException("Currency is not in the ISO 4217 allowlist.");
        }
    }

    public function value(): string { return $this->code; }
}
