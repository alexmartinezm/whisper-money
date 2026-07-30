import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PlanningPeriodNotice } from './planning-period-notice';

describe('PlanningPeriodNotice', () => {
    it('explains that a planning period has no activity yet', () => {
        render(<PlanningPeriodNotice />);

        expect(screen.getByRole('alert')).toHaveClass('bg-amber-50');
        expect(screen.getByText('Planning next period')).toBeInTheDocument();
        expect(
            screen.getByText(
                'This period is ready for planning. Spending and transactions will appear when it starts.',
            ),
        ).toBeInTheDocument();
    });
});
