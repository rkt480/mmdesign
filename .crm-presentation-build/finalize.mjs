import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { PresentationFile } from '@oai/artifact-tool';

const SKILL_DIR = '/Users/raphael/.codex/plugins/cache/openai-primary-runtime/presentations/26.1026.10807/skills/presentations';
const workspaceDir = '/Applications/XAMPP/xamppfiles/htdocs/mmdesign';
const candidatePath = path.join(workspaceDir, '.crm-presentation-build/crm-para-vendedores-draft.pptx');
const finalPath = path.join(workspaceDir, 'apresentacoes/crm-para-vendedores.pptx');
const stagingDir = path.join(workspaceDir, '.crm-presentation-build/finalizer');
await fs.mkdir(stagingDir, { recursive: true });
await fs.mkdir(path.dirname(finalPath), { recursive: true });
const { finalizePresentation } = await import(pathToFileURL(path.join(SKILL_DIR, 'container_tools/artifact_tool_utils.mjs')).href);
const result = await finalizePresentation({
  workspaceDir,
  candidatePath,
  finalPath,
  pythonExecutable: '/Users/raphael/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3',
  integrityValidatorPath: path.join(SKILL_DIR, 'container_tools/inspect_presentation_package_integrity.py'),
  layoutValidatorPath: path.join(SKILL_DIR, 'container_tools/inspect_presentation_layout_geometry.py'),
  layoutArgs: ['--expected-slide-size-emu', '12192000,6858000', '--validate-bullet-geometry', '--validate-heading-fit'],
  explicitTotalSlideCount: 5,
  requiredNativeTableOwnerSlides: [],
  requiredNativeChartOwnerSlides: [],
  fontPolicy: { basis: 'design', families: ['Arial'] },
  verifyArtifactToolImport: true,
  receiptPath: path.join(stagingDir, 'crm-para-vendedores.validation.json'),
});
console.log(JSON.stringify(result, null, 2));
