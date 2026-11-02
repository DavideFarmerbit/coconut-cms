import type {ApiClient} from '../api/ApiClient.ts';
import type {Auth} from '../auth/Auth.ts';
import type {User} from '../auth/User.ts';
import {isDisposing} from './Disposing.ts';
import type {ServiceContext} from './ServiceContext.ts';
import type {ServiceType} from './ServiceType.ts';
import {holdsUnsavedWork} from './UnsavedWork.ts';

/**
 * The services of one signed-in user: one instance of each kind, created
 * the first time it's asked for, shared by every view. When the user
 * changes, the runtime disposes of these and starts new ones, as Unreal's
 * subsystems follow the object that owns them.
 */
export class Services {
    private readonly instances = new Map<ServiceType<unknown>, unknown>();
    /** The kinds being created now, in order: one asking for itself on the way is a loop. */
    private readonly creating: ServiceType<unknown>[] = [];
    private readonly context: ServiceContext;

    public constructor(api: ApiClient, auth: Auth, user: User) {
        this.context = {api, auth, user, service: (type) => this.get(type)};
    }

    /** The one `type` service, created now when it's the first ask. Throws when creating it needs itself. */
    public get<T>(type: ServiceType<T>): T {
        if (this.instances.has(type)) {
            return this.instances.get(type) as T;
        }
        if (this.creating.includes(type)) {
            throw new Error(`The services ${[...this.creating, type].map((each) => each.name).join(' → ')} need each other to be created.`);
        }

        this.creating.push(type);
        try {
            const instance = type.create(this.context);
            this.instances.set(type, instance);

            return instance;
        } finally {
            this.creating.pop();
        }
    }

    /** Whether any service created holds work leaving the page would lose. */
    public get hasUnsaved(): boolean {
        return [...this.instances.values()].some((service) => holdsUnsavedWork(service) && service.hasUnsaved);
    }

    /** Disposes of every service, the last created first: one may still use those it was created with. */
    public dispose(): void {
        for (const service of [...this.instances.values()].reverse()) {
            if (isDisposing(service)) {
                service.dispose();
            }
        }
        this.instances.clear();
    }
}
