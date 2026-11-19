import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

export default function setup(): () => void {
    for (const args of [
        ['artisan', 'migrate', '--force', '--no-interaction'],
        ['artisan', 'db:seed', '--no-interaction'],
        ['artisan', 'tinker', '--no-interaction', '--execute=App\\Models\\User::create(["name" => "E2E Reader", "email" => "reader@smartreport.test", "password" => "password", "roles" => ["report_viewer"]]); App\\Models\\SitTemplate::create(["template_code" => "e2e-private-sit", "template_name" => "Private SIT", "roles" => ["super_admin"], "is_active" => true]);'],
    ]) {
        execFileSync('php', args, { stdio: 'pipe' });
    }

    return () => fs.rmSync(process.env.SMARTREPORT_E2E_STORAGE!, { recursive: true, force: true });
}
