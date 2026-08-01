import {
    store as detect,
    status,
} from '@/actions/App/Http/Controllers/RecurringDetectionController';
import { index } from '@/actions/App/Http/Controllers/RecurringSeriesController';
import HeadingSmall from '@/components/heading-small';
import { CashflowForecastCard } from '@/components/recurring/cashflow-forecast';
import { RecurringOutlook } from '@/components/recurring/recurring-outlook';
import { RecurringSeriesRow } from '@/components/recurring/recurring-series-row';
import { RecurringSummaryCards } from '@/components/recurring/recurring-summary-cards';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { getCsrfToken } from '@/lib/csrf';
import { type BreadcrumbItem } from '@/types';
import {
    type CashflowForecast,
    type RecurringCurrencySummary,
    type RecurringSeries,
} from '@/types/recurring';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Recurring',
        href: index().url,
    },
];

const POLL_INTERVAL_MS = 2000;
/**
 * The job is unique per user, so a rescan requested while another is already
 * running is dropped server-side and its own job id never leaves "queued".
 * Give up after ~2 minutes and refresh anyway rather than poll forever.
 */
const MAX_POLL_ATTEMPTS = 60;

interface Props {
    series: RecurringSeries[];
    summary: RecurringCurrencySummary[];
    forecast: CashflowForecast;
}

export default function RecurringIndex({ series, summary, forecast }: Props) {
    const [scanning, setScanning] = useState(false);
    const pollTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (pollTimer.current) clearTimeout(pollTimer.current);
        },
        [],
    );

    async function pollUntilDone(jobId: string, attempt: number) {
        const response = await fetch(status(jobId).url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            setScanning(false);
            return;
        }

        const progress = (await response.json()) as { status: string };

        if (progress.status === 'completed') {
            setScanning(false);
            router.reload({ only: ['series', 'summary', 'forecast'] });
            toast.success(__('Scan complete.'));
            return;
        }

        if (progress.status === 'failed') {
            setScanning(false);
            toast.error(__('The scan could not be completed.'));
            return;
        }

        if (attempt >= MAX_POLL_ATTEMPTS) {
            setScanning(false);
            router.reload({ only: ['series', 'summary', 'forecast'] });
            return;
        }

        pollTimer.current = setTimeout(
            () => void pollUntilDone(jobId, attempt + 1),
            POLL_INTERVAL_MS,
        );
    }

    async function rescan() {
        setScanning(true);

        try {
            const response = await fetch(detect().url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
            });

            if (!response.ok) {
                setScanning(false);
                toast.error(__('The scan could not be started.'));
                return;
            }

            const { job_id: jobId } = (await response.json()) as {
                job_id: string;
            };

            void pollUntilDone(jobId, 0);
        } catch {
            setScanning(false);
            toast.error(__('The scan could not be started.'));
        }
    }

    const active = series.filter((row) => row.status === 'active');
    // Confirming is the positive half of triage: it says "this commitment is
    // real". Splitting the list makes that purpose visible, and the top
    // section empties out as the user works through it.
    const needsReview = active.filter((row) => row.user_state === 'detected');
    const confirmed = active.filter((row) => row.user_state === 'confirmed');
    const dismissed = active.filter((row) => row.user_state === 'ignored');
    const lapsed = series.filter((row) => row.status === 'lapsed');

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Recurring')} />

            <div className="space-y-8 p-6">
                <div className="flex items-start justify-between gap-2">
                    <HeadingSmall
                        title={__('Recurring')}
                        description={__(
                            'Subscriptions and bills detected from your transactions',
                        )}
                    />
                    <Button
                        variant="outline"
                        onClick={() => void rescan()}
                        disabled={scanning}
                    >
                        {scanning ? (
                            <Spinner className="size-4" />
                        ) : (
                            <RefreshCw className="size-4" />
                        )}
                        {scanning ? __('Scanning…') : __('Scan again')}
                    </Button>
                </div>

                {series.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 p-10 text-center">
                            <p className="font-medium">
                                {__('No recurring charges yet')}
                            </p>
                            <p className="max-w-md text-sm text-muted-foreground">
                                {__(
                                    'A charge needs to appear at least three times on a steady cadence before it shows up here.',
                                )}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <RecurringSummaryCards summary={summary} />

                        <CashflowForecastCard forecast={forecast} />

                        <RecurringOutlook
                            months={forecast.later}
                            currencyCode={forecast.currency_code}
                        />

                        {needsReview.length > 0 && (
                            <section
                                className="space-y-3"
                                aria-labelledby="needs-review-recurring"
                            >
                                <div>
                                    <h2
                                        id="needs-review-recurring"
                                        className="text-base font-semibold"
                                    >
                                        {__('Needs review')}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'Confirm the ones that are real commitments, ignore the rest. Confirmed series are given longer before they count as cancelled.',
                                        )}
                                    </p>
                                </div>
                                <Card>
                                    <CardContent className="divide-y p-0">
                                        {needsReview.map((row) => (
                                            <RecurringSeriesRow
                                                key={row.id}
                                                series={row}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>
                            </section>
                        )}

                        {confirmed.length > 0 && (
                            <section
                                className="space-y-3"
                                aria-labelledby="confirmed-recurring"
                            >
                                <h2
                                    id="confirmed-recurring"
                                    className="text-base font-semibold"
                                >
                                    {__('Confirmed')}
                                </h2>
                                <Card>
                                    <CardContent className="divide-y p-0">
                                        {confirmed.map((row) => (
                                            <RecurringSeriesRow
                                                key={row.id}
                                                series={row}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>
                            </section>
                        )}

                        {dismissed.length > 0 && (
                            <section
                                className="space-y-3"
                                aria-labelledby="dismissed-recurring"
                            >
                                <h2
                                    id="dismissed-recurring"
                                    className="text-base font-semibold"
                                >
                                    {__('Ignored')}
                                </h2>
                                <Card>
                                    <CardContent className="divide-y p-0">
                                        {dismissed.map((row) => (
                                            <RecurringSeriesRow
                                                key={row.id}
                                                series={row}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>
                            </section>
                        )}

                        {lapsed.length > 0 && (
                            <section
                                className="space-y-3"
                                aria-labelledby="lapsed-recurring"
                            >
                                <div>
                                    <h2
                                        id="lapsed-recurring"
                                        className="text-base font-semibold"
                                    >
                                        {__('No longer charging')}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'These stopped arriving on their usual cadence.',
                                        )}
                                    </p>
                                </div>
                                <Card>
                                    <CardContent className="divide-y p-0">
                                        {lapsed.map((row) => (
                                            <RecurringSeriesRow
                                                key={row.id}
                                                series={row}
                                            />
                                        ))}
                                    </CardContent>
                                </Card>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppSidebarLayout>
    );
}
