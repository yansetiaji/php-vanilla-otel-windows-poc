$env:OTEL_PHP_AUTOLOAD_ENABLED = "true"
$env:OTEL_SERVICE_NAME         = "pure-php-api"
$env:OTEL_TRACES_EXPORTER      = "otlp"
$env:OTEL_METRICS_EXPORTER     = "none"
$env:OTEL_LOGS_EXPORTER        = "none"
$env:OTEL_EXPORTER_OTLP_PROTOCOL     = "http/protobuf"
$env:OTEL_EXPORTER_OTLP_ENDPOINT     = "http://10.10.10.102:4318" #Ganti dengan alamat exporter tujuan
$env:OTEL_PROPAGATORS                = "baggage,tracecontext"

php -S localhost:8080 -t public