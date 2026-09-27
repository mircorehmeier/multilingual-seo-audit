<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('seo-audit:about', function (): void {
    $this->info('Multilingual SEO Audit · Laravel edition');
})->purpose('Show project information');
