<?php
namespace App\Contracts;
use Throwable;
interface ErrorReporterInterface{public function report(Throwable $e, array $context=[]): void;}
