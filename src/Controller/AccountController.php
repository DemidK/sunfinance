<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AccountBalance;
use App\Dto\TransactionListQuery;
use App\Entity\Transaction;
use App\Enum\Account;
use App\Exception\ValidationException;
use App\Repository\TransactionRepository;
use App\Validator\ViolationFormatter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AccountController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly TransactionRepository $transactions,
    ) {
    }

    #[Route('/accounts/{id}/balance', name: 'app_accounts_balance', methods: ['GET'])]
    public function balance(string $id): JsonResponse
    {
        $account = $this->account($id);

        return new JsonResponse([
            'account' => $account->value,
            'balances' => array_map(static fn (AccountBalance $balance): array => $balance->toArray(), $this->transactions->balancesByAccount($account)),
        ]);
    }

    #[Route('/accounts/{id}/transactions', name: 'app_accounts_transactions', methods: ['GET'])]
    public function transactions(string $id, Request $request): JsonResponse
    {
        $account = $this->account($id);

        $query = TransactionListQuery::fromQuery($request->query);
        $violations = $this->validator->validate($query);
        if (count($violations) > 0) {
            throw new ValidationException(ViolationFormatter::toArray($violations));
        }

        return new JsonResponse([
            'account' => $account->value,
            'total' => $this->transactions->countByAccount($account, $query),
            'limit' => $query->limit(),
            'offset' => $query->offset(),
            'items' => array_map(self::serialize(...), $this->transactions->findByAccount($account, $query)),
        ]);
    }

    private function account(string $id): Account
    {
        return Account::tryFrom($id) ?? throw new NotFoundHttpException(sprintf('Unknown account "%s".', $id));
    }

    private static function serialize(Transaction $transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'event_id' => $transaction->getEventId(),
            'direction' => $transaction->getDirection()->value,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'created_at' => $transaction->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
