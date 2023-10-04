<?php

namespace App\Tests\Mock\Response;

use Symfony\Component\HttpClient\Response\MockResponse;

interface ResponseBuilderInterface
{
    public function build(): MockResponse;
}
