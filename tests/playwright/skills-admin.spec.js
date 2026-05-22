const { test, expect } = require('@playwright/test');

const baseURL = (process.env.OPENMIRA_WP_ENV_BASE_URL || 'http://localhost:8888').replace(/\/$/, '');
const username = process.env.OPENMIRA_WP_ENV_USERNAME || 'admin';
const password = process.env.OPENMIRA_WP_ENV_PASSWORD || 'password';

test('Skills admin renders and creates a CPT-backed skill', async ({ page }) => {
  await page.goto(`${baseURL}/wp-login.php`);
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/\/wp-admin\//, { timeout: 15000 });

  await page.goto(`${baseURL}/wp-admin/admin.php?page=openmira-skills`);
  await expect(page.locator('body')).not.toContainText('Sorry, you are not allowed to access this page');
  await expect(page.getByRole('heading', { name: 'Open Mira Skills', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Built-in Skills' })).toBeVisible();

  await page.getByRole('link', { name: 'Add Skill' }).click();
  await expect(page.getByRole('heading', { name: 'Add Skill' })).toBeVisible();

  const skillId = `ci-ui-skill-${Date.now()}`;
  await page.locator('#openmira-skill-id').fill(skillId);
  await page.locator('#openmira-skill-title').fill('CI UI Skill');
  await page.locator('#openmira-skill-description').fill('Created by the Playwright admin UI smoke.');

  const body = '# CI UI Skill\n\nCreated by the Playwright admin UI smoke.';
  const codeMirrorCount = await page.locator('.CodeMirror').count();
  if (codeMirrorCount > 0) {
    await page.evaluate((value) => {
      const editor = Array.from(document.querySelectorAll('.CodeMirror'))
        .map((node) => node.CodeMirror)
        .find(Boolean);
      if (!editor) {
        throw new Error('CodeMirror wrapper exists but no editor instance was found.');
      }
      editor.setValue(value);
      editor.save();
    }, body);
  } else {
    await page.locator('#openmira-skill-body').fill(body);
  }

  await page.getByRole('button', { name: 'Create Skill' }).click();
  await expect(page.locator('.notice-success')).toContainText('Skill created', { timeout: 15000 });
  await expect(page.locator('table.wp-list-table')).toContainText(skillId);
  await expect(page.locator('table.wp-list-table')).toContainText('CPT');
  await expect(page.locator('table.wp-list-table')).toContainText('Enabled');
});
