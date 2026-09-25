<?php
/** Job handler: build one "Download Your Data" export (DataExportService::build). */
class DataExportJob {

    public static function handle(array $payload): string {
        $id = (int) ($payload['export_id'] ?? 0);
        if ($id <= 0) { throw new InvalidArgumentException('export_id required'); }
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);
        return DataExportService::build($id);
    }
}
