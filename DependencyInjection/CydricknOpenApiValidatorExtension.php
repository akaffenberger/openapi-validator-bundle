<?php

declare(strict_types=1);

namespace Cydrickn\OpenApiValidatorBundle\DependencyInjection;

use Cydrickn\OpenApiValidatorBundle\EventListener\Condition\QueryParameterCondition;
use Cydrickn\OpenApiValidatorBundle\EventListener\ValidatorListener;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\JsonFileFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\NelmioFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\YamlFileFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Schema;
use Cydrickn\OpenApiValidatorBundle\Validator\Validator;
use League\OpenAPIValidation\PSR7\SchemaFactory\FileFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\PHPFileFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\JsonDirFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\YamlDirFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\PHPDirFactory;
use Cydrickn\OpenApiValidatorBundle\Schema\Factory\DirFactory;

class CydricknOpenApiValidatorExtension extends ConfigurableExtension
{
    private const SCHEMAS = [
        'json-file' => JsonFileFactory::class,
        'yaml-file' => YamlFileFactory::class,
        'nelmio' => NelmioFactory::class,
        'php-file' => PHPFileFactory::class,
        'json-dir' => JsonDirFactory::class,
        'yaml-dir' => YamlDirFactory::class,
        'php-dir' => PHPDirFactory::class,
    ];

    private const CONDITIONS = [
        'query' => QueryParameterCondition::class
    ];

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container)
    {
        $factoryClass = self::SCHEMAS[$mergedConfig['schema']['factory']];
        $factoryArguments = [];
        if (in_array(FileFactory::class, class_parents($factoryClass))) {
            $factoryArguments['$filename'] = $mergedConfig['schema']['file'];
        } elseif (in_array(DirFactory::class, class_parents($factoryClass))) {
            $factoryArguments['$dirName'] = $mergedConfig['schema']['dir'];
        } elseif ($factoryClass === NelmioFactory::class) {
            $factoryArguments[] = new Reference('nelmio_api_doc.generator_locator');
        }
        $schemaFactory = new Definition($factoryClass, $factoryArguments);
        $schemaFactory->setAutoconfigured(true);
        $schemaFactory->setAutowired(true);
        $container->setDefinition('cydrickn.openapi_validator.schema_factory', $schemaFactory);

        $schema = new Definition(Schema::class);
        $schema->setFactory([new Reference('cydrickn.openapi_validator.schema_factory'), 'createSchema']);
        $container->setDefinition('cydrickn.openapi_validator.schema', $schema);

        $validator = new Definition(Validator::class, [
            new Reference('cydrickn.openapi_validator.schema'),
        ]);
        $container->setDefinition('cydrickn.openapi_validator.validator', $validator);

        if ($conditionInfo = $mergedConfig['condition'] ?? null) {
            $container->setDefinition('cydrickn.openapi_validator.condition', (function() use ($conditionInfo) {
                $type = array_keys($conditionInfo)[0];
                $args = array_values($conditionInfo)[0];
                return new Definition(self::CONDITIONS[$type], [$args]);
            })());
        }

        $validatorListener = new Definition(ValidatorListener::class, [
            new Reference('cydrickn.openapi_validator.validator'),
            !empty($mergedConfig['condition']) ? new Reference('cydrickn.openapi_validator.condition') : null
        ]);
        if ($mergedConfig['validate_request']) {
            $validatorListener->addTag('kernel.event_listener', ['event' => 'kernel.request']);
        }
        if ($mergedConfig['validate_response']) {
            $validatorListener->addTag('kernel.event_listener', ['event' => 'kernel.response']);
        }
        $validatorListener->addTag('kernel.event_listener', ['event' => 'kernel.exception']);
        $container->setDefinition('cydrickn.openapi_validator.validator_listener', $validatorListener);
    }
}