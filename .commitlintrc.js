module.exports = {
    extends: ['@commitlint/config-conventional'],
    rules: {
        'header-trim': [1, 'always'],
        'body-leading-blank': [2, 'always'],
        'subject-empty': [0, 'never'],
        'subject-case': [2, 'always', 'sentence-case'],
        'type-empty': [1, 'never']
    },
    ignores: [
        message => message.includes('Pull request #')
    ]
}

