const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const baseURL = (process.env.OPENMIRA_WP_ENV_BASE_URL || 'http://localhost:8888').replace(/\/$/, '');
const username = process.env.OPENMIRA_WP_ENV_USERNAME || 'admin';
const password = process.env.OPENMIRA_WP_ENV_PASSWORD || 'password';

test('Skills admin renders and creates a CPT-backed skill', async ({ page }) => {
  if (process.env.OPENMIRA_WP_ENV_AUTO_LOGIN === '1') {
    await page.goto(`${baseURL}/?openmira_ci_login=1&openmira_ci_redirect=openmira-skills`);
  } else {
    await page.goto(`${baseURL}/wp-login.php`);
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
  }
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
  const customSkillsTable = page.locator('table.wp-list-table').first();
  await expect(customSkillsTable).toContainText(skillId);
  await expect(customSkillsTable).toContainText('CPT');
  await expect(customSkillsTable).toContainText('Enabled');

  await page.getByRole('button', { name: 'Import' }).click();
  const importIds = [`ci-multi-one-${Date.now()}`, `ci-multi-two-${Date.now()}`];
  const importDir = fs.mkdtempSync(path.join(os.tmpdir(), 'openmira-skills-'));
  const importFiles = importIds.map((id) => {
    const filePath = path.join(importDir, `${id}.md`);
    const title = id.replace(/-/g, ' ');
    fs.writeFileSync(
      filePath,
      `---\ntitle: "${title}"\ndescription: "Imported by Playwright."\nenable_prompt: true\n---\n\n# ${title}\n\nBody.\n`,
    );
    return filePath;
  });
  await page.locator('#openmira-skill-import-file').setInputFiles(importFiles);
  await page.getByRole('button', { name: 'Import Skills' }).click();
  await expect(page.locator('.notice-success')).toContainText('Skills import complete', { timeout: 15000 });
  const importSummary = page.locator('.openmira-admin-result-list');
  await expect(importSummary).toBeVisible();
  for (const importId of importIds) {
    await expect(importSummary).toContainText(`${importId}.md`);
    await expect(importSummary).toContainText('1 imported');
    await expect(page.locator('table.wp-list-table').first()).toContainText(importId);
  }

  page.once('dialog', async (dialog) => {
    await dialog.accept();
  });
  await page.locator('tr', { hasText: skillId }).getByRole('button', { name: 'Trash' }).click();
  await expect(page.locator('.notice-success')).toContainText('Skill moved to trash', { timeout: 15000 });
  await expect(page.getByRole('heading', { name: 'Trashed Skills' })).toBeVisible();
  const trashedSkillsTable = page.locator('h2:has-text("Trashed Skills") + table.wp-list-table');
  await expect(trashedSkillsTable).toContainText(skillId);
  await expect(trashedSkillsTable.locator('tr', { hasText: skillId })).toContainText('Not registered');

  await page.getByRole('link', { name: 'Active' }).click();
  await expect(page.locator('table.wp-list-table').first()).not.toContainText(skillId);

  await page.getByRole('link', { name: 'Trashed' }).click();
  const trashedOnlyTable = page.locator('table.wp-list-table').first();
  await expect(trashedOnlyTable).toContainText(skillId);
  await expect(trashedOnlyTable.locator('tr', { hasText: skillId })).toContainText('Not registered');
  await trashedOnlyTable.locator('tr', { hasText: skillId }).getByRole('button', { name: 'Restore' }).click();
  await expect(page.locator('.notice-success')).toContainText('Skill restored', { timeout: 15000 });

  await page.getByRole('link', { name: 'Active' }).click();
  await expect(page.locator('table.wp-list-table').first()).toContainText(skillId);
});
