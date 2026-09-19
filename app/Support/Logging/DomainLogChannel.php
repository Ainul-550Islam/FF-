<?php
namespace App\Support\Logging;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
class DomainLogChannel
{
    public function __invoke(array $config)
    {
        $path=$config['path']??storage_path('logs/ffarena.log'); $level=$config['level']??'debug'; $days=$config['days']??14;
        $logger=new Logger('ffarena'); $handler=new RotatingFileHandler($path,$days,$level);
        $handler->pushProcessor(new RequestContextProcessor()); $handler->pushProcessor(new RedactSensitiveDataProcessor()); $handler->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler($handler); return $logger;
    }
    public static function config(string $domain): array
    {
        return ['driver'=>'monolog','level'=>env('LOG_LEVEL','debug'),'handler'=>RotatingFileHandler::class,'handler_with'=>['filename'=>storage_path("logs/{$domain}.log"),'maxFiles'=>(int)env('LOG_DAILY_DAYS',14)],'processors'=>[PsrLogMessageProcessor::class,RequestContextProcessor::class,RedactSensitiveDataProcessor::class]];
    }
}
