<?php

declare(strict_types=1);

namespace App\Api\Request;

use App\Api\Exception\BadRequestException;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

trait DataFilterTrait
{
    /**
     * @throws BadRequestException
     */
    protected static function filterData(array $constraints, array $params, ?array &$errors = null): array
    {
        $data = \array_fill_keys(\array_keys($constraints), null);
        $data = \array_replace($data, $params);
        $data = \array_intersect_key($data, $constraints); // leave only needed fields

        $violations = Validation::createValidator()->validate($data, new Collection($constraints));
        if ($violations->count()) {
            $errs = [];
            foreach ($violations as $violation) {
                /** @var ConstraintViolationInterface $violation */
                $errs[] = [
                    'field' => \trim($violation->getPropertyPath(), '[]'),
                    'message' => $violation->getMessage(),
                ];
            }

            if ($errors === null) {
                throw BadRequestException::errors($errs);
            }

            $errors = \array_merge($errors, $errs);
        }

        return \array_values(
            \array_replace($constraints, $data),
        );
    }
}
