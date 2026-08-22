import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

/**
 * Flat config, deliberately minimal.
 *
 * Only `typescript-eslint` is pulled in — its recommended set already covers
 * the JavaScript core rules that matter here, and every additional preset is
 * another dependency to keep in step with the ESLint major version for very
 * little return.
 */
export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'playwright-report', 'test-results', 'coverage'] },

  ...tseslint.configs.recommended,

  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
    },
    plugins: {
      'react-hooks': reactHooks,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,

      // Unused variables are usually a leftover from a refactor. The underscore
      // escape hatch keeps deliberately-ignored parameters from becoming noise.
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],

      // `any` defeats the point of the zod boundary: responses are validated on
      // the way in precisely so the rest of the app can rely on their types.
      '@typescript-eslint/no-explicit-any': 'error',

      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },
);
