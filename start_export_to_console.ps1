$env:OTEL_PHP_AUTOLOAD_ENABLED = "true"
$env:OTEL_SERVICE_NAME         = "pure-php-api"
$env:OTEL_TRACES_EXPORTER      = "console"
$env:OTEL_METRICS_EXPORTER     = "none"
$env:OTEL_LOGS_EXPORTER        = "none"
$env:OTEL_PHP_TRACES_PROCESSOR = "simple"
$env:OTEL_PHP_LOG_DESTINATION  = "stderr"

php -S localhost:8080 -t public