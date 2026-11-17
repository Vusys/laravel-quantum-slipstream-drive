<?php

declare(strict_types=1);

namespace Vusys\QuantumSlipstreamDrive;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\QuantumSlipstreamDrive\Query\IdentityMapBuilder;
use Vusys\QuantumSlipstreamDrive\Query\RawWriteInterceptor;

/**
 * All-models mode: the identity map for every Eloquent model, without the
 * {@see HasIdentityMap} trait.
 *
 * Two pieces, both installed once at boot:
 *
 *  1. Eloquent's default builder class (`Model::$builder`) becomes
 *     {@see IdentityMapBuilder}. A model that already chooses its own builder
 *     (a `$builder` override, `#[UseEloquentBuilder]`, or its own
 *     `newEloquentBuilder()`) keeps it and is left alone.
 *  2. Wildcard model-event listeners keep the store in step, exactly as the
 *     trait's per-class listeners do. They skip trait models, which have their
 *     own, and models whose builder isn't the identity map's.
 *
 * What the trait adds on top (the in-memory relation classes and the cached
 * table name) stays trait-only.
 *
 * @internal
 */
final class AllModels
{
    /**
     * Whether a class is served by this mode, cached per class. Process-stable,
     * like {@see RawWriteInterceptor}'s registry: a class's builder never changes.
     *
     * @var array<class-string<Model>, bool>
     */
    private static array $covered = [];

    private static bool $swappedBuilder = false;

    public static function install(Dispatcher $events): void
    {
        self::swapDefaultBuilder();

        $events->listen('eloquent.retrieved: *', self::forward(ModelLifecycle::retrieved(...)));
        $events->listen('eloquent.saved: *', self::forward(ModelLifecycle::saved(...)));
        $events->listen('eloquent.deleted: *', self::forward(ModelLifecycle::deleted(...)));
        $events->listen('eloquent.restored: *', self::forward(ModelLifecycle::restored(...)));
        $events->listen('eloquent.forceDeleted: *', self::forward(ModelLifecycle::forceDeleted(...)));
    }

    /**
     * Put Eloquent's default builder back. For tests, which boot the app many
     * times in one process.
     */
    public static function uninstall(): void
    {
        if (self::$swappedBuilder) {
            self::setDefaultBuilder(Builder::class);
            self::$swappedBuilder = false;
        }

        self::$covered = [];
    }

    public static function covers(Model $model): bool
    {
        return self::$covered[$model::class] ??= ! in_array(HasIdentityMap::class, class_uses_recursive($model), true)
            && $model->newModelQuery() instanceof IdentityMapBuilder;
    }

    /**
     * @param  \Closure(Model): void  $handler
     * @return \Closure(string, array<int, mixed>): void
     */
    private static function forward(\Closure $handler): \Closure
    {
        return static function (string $event, array $payload) use ($handler): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model || ! self::covers($model)) {
                return;
            }

            // The store only ever holds models that reached an event, so registering
            // here is early enough for raw writes to invalidate them.
            resolve(RawWriteInterceptor::class)->registerModel($model::class);
            $handler($model);
        };
    }

    private static function swapDefaultBuilder(): void
    {
        if (self::$swappedBuilder) {
            return;
        }

        $current = \Closure::bind(static fn (): string => Model::$builder, null, Model::class)();

        // Someone else already replaced the default; replacing theirs would be a regression.
        if ($current !== Builder::class) {
            return;
        }

        self::setDefaultBuilder(IdentityMapBuilder::class);
        self::$swappedBuilder = true;
    }

    /** @param class-string<Builder<Model>> $class */
    private static function setDefaultBuilder(string $class): void
    {
        \Closure::bind(static function () use ($class): void {
            Model::$builder = $class;
        }, null, Model::class)();
    }
}
