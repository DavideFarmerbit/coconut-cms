/** An edit the schema draft refuses, saying why: a name taken, a native type touched. Nothing changed. */
export class EditRefused extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'EditRefused';
    }
}
