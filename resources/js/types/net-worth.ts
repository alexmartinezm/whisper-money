export interface NetWorthProjectionPoint {
    date: string;
    actual: number | null;
    committed: number | null;
    estimated: number | null;
}

export interface NetWorthProjection {
    currency_code: string;
    months: number;
    today: number;
    committed: number;
    estimated: number | null;
    points: NetWorthProjectionPoint[];
    drivers: {
        debt_repaid: number;
        property_growth: number;
        recurring_net: number;
        contributions: number;
        everyday_spending: number | null;
    };
    assumptions: {
        investments_flat: boolean;
        revalued_accounts: string[];
        months_observed: number | null;
    };
}
