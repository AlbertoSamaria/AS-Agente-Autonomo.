<?php
declare(strict_types=1);

final class ContextManager
{
    public function fit(array $messages, int $maxCharacters = 24000): array
    {
        $maxCharacters = max(4000, $maxCharacters);
        $normalized = [];

        foreach ($messages as $message) {
            if (
                !is_array($message)
                || !isset($message['role'], $message['content'])
                || !is_string($message['role'])
                || !is_string($message['content'])
                || !in_array($message['role'], ['system', 'user', 'assistant'], true)
            ) {
                continue;
            }

            $normalized[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('A conversa não contém mensagens utilizáveis.');
        }

        $system = $normalized[0]['role'] === 'system'
            ? array_shift($normalized)
            : null;
        $latest = array_pop($normalized);
        $latestBudget = min(8000, max(1000, (int) floor($maxCharacters * 0.35)));
        if (mb_strlen($latest['content']) > $latestBudget) {
            $latest['content'] = mb_substr($latest['content'], -$latestBudget);
        }

        $systemBudget = max(
            2000,
            min(12000, $maxCharacters - mb_strlen($latest['content']) - 1000)
        );
        if ($system !== null && mb_strlen($system['content']) > $systemBudget) {
            $headLength = (int) floor($systemBudget * 0.75);
            $tailLength = $systemBudget - $headLength;
            $system['content'] = mb_substr($system['content'], 0, $headLength) .
                "\n[Contexto intermédio omitido para respeitar o limite.]\n" .
                mb_substr($system['content'], -$tailLength);
        }

        $remaining = max(
            0,
            $maxCharacters
                - mb_strlen((string) ($system['content'] ?? ''))
                - mb_strlen($latest['content'])
        );
        $history = [];
        for ($index = count($normalized) - 1; $index >= 0; $index--) {
            $message = $normalized[$index];
            $length = mb_strlen($message['content']);
            if ($length > $remaining) {
                continue;
            }

            array_unshift($history, $message);
            $remaining -= $length;
        }

        $result = [];
        if ($system !== null) {
            $result[] = $system;
        }
        return array_merge($result, $history, [$latest]);
    }
}
