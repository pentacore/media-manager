/** `YYYY-MM-DD` of an instant in the given IANA timezone. */
export function dayKey(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(iso));
}

/** The month grid as weeks of `YYYY-MM-DD`, starting on `firstDayOfWeek` (0 = Sunday). */
export function monthGrid(month: string, firstDayOfWeek: number): string[][] {
    const [year, monthNumber] = month.split('-').map(Number);
    const first = new Date(Date.UTC(year, monthNumber - 1, 1));
    const offset = (first.getUTCDay() - firstDayOfWeek + 7) % 7;
    const cursor = new Date(Date.UTC(year, monthNumber - 1, 1 - offset));
    const weeks: string[][] = [];

    do {
        const week: string[] = [];

        for (let day = 0; day < 7; day++) {
            week.push(cursor.toISOString().slice(0, 10));
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }

        weeks.push(week);
    } while (cursor.getUTCMonth() === monthNumber - 1);

    return weeks;
}

export function shiftMonth(month: string, delta: number): string {
    const [year, monthNumber] = month.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, monthNumber - 1 + delta, 1));

    return shifted.toISOString().slice(0, 7);
}
