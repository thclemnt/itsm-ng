import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter';

export default class CompletionReporter implements Reporter {
  private incomplete = false;

  onTestEnd(test: TestCase, result: TestResult): void {
    // CI must exercise every case, including tests conditionally skipped by missing fixtures.
    if (result.status === 'skipped' || result.status === 'interrupted') {
      this.incomplete = true;
      console.error(`Incomplete browser case (${result.status}): ${test.titlePath().join(' > ')}`);
    }
  }

  async onEnd(): Promise<{ status: 'failed' } | void> {
    if (this.incomplete) return { status: 'failed' };
  }
}
