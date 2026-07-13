<?php

namespace App\Twig;

use App\Service\AccessControl;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AccessExtension extends AbstractExtension
{
    public function __construct(private readonly AccessControl $access)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', [$this->access, 'can']),
        ];
    }
}
