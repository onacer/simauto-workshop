<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

final class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('money', [$this, 'money'], ['is_safe' => ['html']])];
    }

    public function money(float|int|string|null $value, int $decimals = 2): Markup
    {
        $formatted = number_format((float) $value, $decimals, ',', ' ');
        return new Markup('<span class="money" dir="ltr">' . $formatted . ' DH</span>', 'UTF-8');
    }
}
