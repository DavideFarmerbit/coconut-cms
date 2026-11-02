/** A service holding work that leaving the page would lose: the browser asks first while it has some. */
export interface UnsavedWork {
    readonly hasUnsaved: boolean;
}

/** Whether `service` holds work leaving the page would lose. */
export function holdsUnsavedWork(service: unknown): service is UnsavedWork {
    return typeof (service as Partial<UnsavedWork> | null)?.hasUnsaved === 'boolean';
}
