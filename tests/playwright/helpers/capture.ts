import { Page } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

const SHOTS_DIR = path.resolve(__dirname, '..', 'playwright-results', 'screenshots');

export async function shot(page: Page, name: string): Promise<string> {
  if (!fs.existsSync(SHOTS_DIR)) {
    fs.mkdirSync(SHOTS_DIR, { recursive: true });
  }
  const file = path.join(SHOTS_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

/**
 * Attach console & pageerror listeners. Returns a function that returns the
 * collected entries when called.
 */
export function startConsoleCapture(page: Page): {
  errors: string[];
  warnings: string[];
  pageErrors: string[];
  failedRequests: { url: string; failure: string }[];
} {
  const errors: string[] = [];
  const warnings: string[] = [];
  const pageErrors: string[] = [];
  const failedRequests: { url: string; failure: string }[] = [];

  page.on('console', (msg) => {
    const type = msg.type();
    const text = msg.text();
    if (type === 'error') errors.push(text);
    if (type === 'warning') warnings.push(text);
  });
  page.on('pageerror', (err) => {
    pageErrors.push(err.message);
  });
  page.on('requestfailed', (req) => {
    failedRequests.push({
      url: req.url(),
      failure: req.failure()?.errorText ?? 'unknown',
    });
  });

  return { errors, warnings, pageErrors, failedRequests };
}
