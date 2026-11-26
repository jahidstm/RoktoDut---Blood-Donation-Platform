<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BloodRequest;
use App\Models\User;
use App\Services\Analytics\DemandAnalytics;
use App\Services\Analytics\DemandForecast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request, DemandAnalytics $analytics, DemandForecast $forecast)
    {
        $requested = $request->query('source');
        $autoDemo = false;

        if (in_array($requested, [DemandAnalytics::SOURCE_REAL, DemandAnalytics::SOURCE_DEMO, DemandAnalytics::SOURCE_ALL], true)) {
            $source = $requested;
        } else {
            $source = DemandAnalytics::SOURCE_REAL;
            $tooFew = $analytics->countRequests(DemandAnalytics::SOURCE_REAL) < (int) config('analytics.min_real_requests', 30);
            if ($tooFew && $analytics->countRequests(DemandAnalytics::SOURCE_DEMO) > 0) {
                $source = DemandAnalytics::SOURCE_DEMO;
                $autoDemo = true;
            }
        }

        $data = Cache::remember("admin.analytics.demand.v2.{$source}", now()->addMinutes(10), fn () => $analytics->compute($source));

        return view('admin.analytics.index', [
            'data' => $data,
            'source' => $source,
            'autoDemo' => $autoDemo,
            'realCount' => $analytics->countRequests(DemandAnalytics::SOURCE_REAL),
            'minReal' => (int) config('analytics.min_real_requests', 30),
            'totalDonors' => User::query()->where('is_donor', true)->count(),
            'forecast' => $forecast->latest(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $fileName = 'rokto_dut_analytics_export_' . now()->format('Y_m_d') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Donor Analytics (Anonymized)']);
            fputcsv($handle, [
                'Donor ID',
                'Name',
                'Masked Email',
                'Masked Phone',
                'Blood Group',
                'District',
                'NID',
                'Total Verified Donations',
                'Points',
            ]);

            User::query()
                ->leftJoin('districts', 'districts.id', '=', 'users.district_id')
                ->where('users.role', 'donor')
                ->select([
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.phone',
                    'users.blood_group',
                    'users.nid_number',
                    'users.total_verified_donations',
                    'users.points',
                    'districts.name as district_name',
                ])
                ->orderBy('users.id')
                ->cursor()
                ->each(function ($row) use ($handle) {
                    fputcsv($handle, [
                        $row->id,
                        $row->name,
                        $this->maskEmail((string) $row->email),
                        $this->maskPhone((string) $row->phone),
                        $row->blood_group,
                        $row->district_name,
                        $this->maskNid((string) $row->nid_number),
                        (int) ($row->total_verified_donations ?? 0),
                        (int) ($row->points ?? 0),
                    ]);
                });

            fputcsv($handle, []);
            fputcsv($handle, ['Request Analytics (Anonymized)']);
            fputcsv($handle, [
                'Request ID',
                'Blood Group',
                'Bags Needed',
                'Status',
                'Needed At',
                'Created At',
                'District',
                'Requester Name',
                'Requester Email (Masked)',
                'Requester Phone (Masked)',
            ]);

            BloodRequest::query()
                ->leftJoin('users', 'users.id', '=', 'blood_requests.requested_by')
                ->leftJoin('districts', 'districts.id', '=', 'blood_requests.district_id')
                ->select([
                    'blood_requests.id',
                    'blood_requests.blood_group',
                    'blood_requests.bags_needed',
                    'blood_requests.status',
                    'blood_requests.needed_at',
                    'blood_requests.created_at',
                    'districts.name as district_name',
                    'users.name as requester_name',
                    'users.email as requester_email',
                    'users.phone as requester_phone',
                ])
                ->orderBy('blood_requests.id')
                ->cursor()
                ->each(function ($row) use ($handle) {
                    fputcsv($handle, [
                        $row->id,
                        $row->blood_group,
                        (int) ($row->bags_needed ?? 0),
                        $row->status,
                        $row->needed_at,
                        $row->created_at,
                        $row->district_name,
                        $row->requester_name,
                        $this->maskEmail((string) $row->requester_email),
                        $this->maskPhone((string) $row->requester_phone),
                    ]);
                });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (!$digits) {
            return '';
        }

        if (strlen($digits) <= 6) {
            return substr($digits, 0, 2) . '***';
        }

        return substr($digits, 0, 5) . '***' . substr($digits, -3);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '';
        }

        [$name, $domain] = explode('@', $email, 2);
        $prefix = mb_substr($name, 0, 2);
        return $prefix . '***@' . $domain;
    }

    private function maskNid(string $nid): string
    {
        return trim($nid) === '' ? '' : 'সংরক্ষিত';
    }
}
