/** A service letting go of what it holds once its user signed out: listeners, sign-out guards. */
export interface Disposing {
    dispose(): void;
}

/** Whether `service` lets go of what it holds when disposed of. */
export function isDisposing(service: unknown): service is Disposing {
    return typeof (service as Partial<Disposing> | null)?.dispose === 'function';
}
