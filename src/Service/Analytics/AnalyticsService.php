<?php
// src/Service/Analytics/AnalyticsService.php
namespace App\Service\Analytics;

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;

class AnalyticsService
{
    private BetaAnalyticsDataClient $client;
    private string $propertyId;

    public function __construct(string $credentialsPath, string $propertyId)
    {
        $this->client = new BetaAnalyticsDataClient([
            'credentials' => $credentialsPath,
        ]);
        $this->propertyId = $propertyId;
    }

    public function getStats(string $startDate = '30daysAgo', string $endDate = 'today'): array
    {
        $dateRange = new DateRange(['start_date' => $startDate, 'end_date' => $endDate]);
        $property  = 'properties/' . $this->propertyId;

        // ── 1. Trafic quotidien (visiteurs + pages vues par jour) ──────────
        $dailyResponse = $this->client->runReport(new RunReportRequest([
            'property'    => $property,
            'date_ranges' => [$dateRange],
            'dimensions'  => [new Dimension(['name' => 'date'])],
            'metrics'     => [
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'screenPageViews']),
            ],
            'order_bys'   => [
                new OrderBy(['dimension' => new DimensionOrderBy(['dimension_name' => 'date'])]),
            ],
        ]));

        // ── 2. Sources de trafic ───────────────────────────────────────────
        $sourcesResponse = $this->client->runReport(new RunReportRequest([
            'property'    => $property,
            'date_ranges' => [$dateRange],
            'dimensions'  => [new Dimension(['name' => 'sessionDefaultChannelGroup'])],
            'metrics'     => [new Metric(['name' => 'activeUsers'])],
        ]));

        // ── 3. Top pages ───────────────────────────────────────────────────
        $pagesResponse = $this->client->runReport(new RunReportRequest([
            'property'    => $property,
            'date_ranges' => [$dateRange],
            'dimensions'  => [new Dimension(['name' => 'pagePath'])],
            'metrics'     => [new Metric(['name' => 'activeUsers'])],
            'limit'       => 10,
            'order_bys'   => [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'activeUsers']),
                    'desc'   => true,
                ]),
            ],
        ]));

        // ── 4. Overview global ─────────────────────────────────────────────
        $overviewResponse = $this->client->runReport(new RunReportRequest([
            'property'    => $property,
            'date_ranges' => [$dateRange],
            'metrics'     => [
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'screenPageViewsPerSession']),
                new Metric(['name' => 'bounceRate']),
            ],
        ]));

        // ── Formatage ──────────────────────────────────────────────────────
        $daily = [];
        foreach ($dailyResponse->getRows() as $row) {
            $daily[] = [
                'date'      => $row->getDimensionValues()[0]->getValue(),
                'visiteurs' => (int) $row->getMetricValues()[0]->getValue(),
                'pagesVues' => (int) $row->getMetricValues()[1]->getValue(),
            ];
        }

        $sources = [];
        foreach ($sourcesResponse->getRows() as $row) {
            $sources[] = [
                'source'    => $row->getDimensionValues()[0]->getValue(),
                'visiteurs' => (int) $row->getMetricValues()[0]->getValue(),
            ];
        }

        $topPages = [];
        foreach ($pagesResponse->getRows() as $row) {
            $topPages[] = [
                'page'      => $row->getDimensionValues()[0]->getValue(),
                'visiteurs' => (int) $row->getMetricValues()[0]->getValue(),
            ];
        }

        $overviewRow = $overviewResponse->getRows()[0] ?? null;
        $overview = [
            'totalVisiteurs'           => $overviewRow ? (int) $overviewRow->getMetricValues()[0]->getValue() : 0,
            'totalPagesVues'           => $overviewRow ? (int) $overviewRow->getMetricValues()[1]->getValue() : 0,
            'moyennePagesParSession'   => $overviewRow ? round((float) $overviewRow->getMetricValues()[2]->getValue(), 1) : 0,
            'tauxRebond'               => $overviewRow ? round((float) $overviewRow->getMetricValues()[3]->getValue() * 100, 1) : 0,
        ];

        return [
            'overview'  => $overview,
            'daily'     => $daily,
            'sources'   => $sources,
            'topPages'  => $topPages,
            'period'    => "$startDate → $endDate",
        ];
    }
}
