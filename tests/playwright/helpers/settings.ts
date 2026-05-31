import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { WP_CLI_CMD } from './env';

const SNAPSHOT_PATH = path.resolve(__dirname, '..', 'snapshots', 'tssl_settings.snapshot.json');

const WP_CLI_BASE = WP_CLI_CMD;

function runCli(args: string, stdin?: string): string {
  try {
    const out = execSync(`${WP_CLI_BASE} ${args}`, {
      encoding: 'utf8',
      input: stdin,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    return out;
  } catch (e: unknown) {
    const err = e as { stdout?: string; stderr?: string; message: string };
    throw new Error(
      `wp-cli failed: ${args}\nstdout: ${err.stdout ?? ''}\nstderr: ${err.stderr ?? ''}\nmessage: ${err.message}`,
    );
  }
}

export type TsslSettings = {
  enable_two_step: 0 | 1;
  disable_password_reset: 0 | 1;
  hide_lost_password: 0 | 1;
  hide_default_login_urls: 0 | 1;
  custom_login_slug: string;
  auto_create_login_page: 0 | 1;
  hide_login_page_from_lists: 0 | 1;
  login_page_id: number;
  captcha_provider: 'none' | 'cloudflare_turnstile' | 'google_recaptcha' | 'google_recaptcha_v2' | 'google_recaptcha_v3';
  captcha_location: 'username_step' | 'password_step' | 'both';
  recaptcha_mode: 'checkbox' | 'score';
  turnstile_site_key: string;
  turnstile_secret_key: string;
  recaptcha_site_key: string;
  recaptcha_secret_key: string;
  recaptcha_v3_site_key: string;
  recaptcha_v3_secret_key: string;
  recaptcha_v3_threshold: number;
  blocked_login_behavior: 'show_404' | 'redirect_home' | 'redirect_custom_url';
  blocked_login_custom_url: string;
  enable_login_branding: 0 | 1;
  login_logo_id: number;
  login_logo_max_width: string;
  login_logo_alt: string;
};

export function getSettings(): TsslSettings {
  const raw = runCli('option get tssl_settings --format=json');
  return JSON.parse(raw) as TsslSettings;
}

export function updateSettings(partial: Partial<TsslSettings>): TsslSettings {
  const current = getSettings();
  const merged = { ...current, ...partial };
  runCli('option update tssl_settings --format=json', JSON.stringify(merged));
  return merged as TsslSettings;
}

export function restoreSettingsFromSnapshot(): void {
  // The snapshot was captured against the local dev environment, where the
  // auto-created login page happens to be at a specific post ID. That ID is
  // meaningless on any other site (a fresh CI WordPress will create the
  // page at a different ID), and restoring the snapshot's value verbatim
  // would break every test that relies on the plugin knowing which page
  // hosts the login portal. Preserve whatever the current install thinks
  // login_page_id is.
  const snapshot = JSON.parse(fs.readFileSync(SNAPSHOT_PATH, 'utf8')) as Record<string, unknown>;
  const current = getSettings() as unknown as Record<string, unknown>;
  snapshot.login_page_id = current.login_page_id ?? 0;
  runCli('option update tssl_settings --format=json', JSON.stringify(snapshot));
}

export function resetUserPassword(login: string, pass: string): void {
  runCli(`user update ${login} --user_pass=${JSON.stringify(pass)}`);
}
