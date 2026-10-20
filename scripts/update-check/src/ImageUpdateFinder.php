<?php

namespace UpdateCheck;

use Throwable;

/**
 * 固定しているイメージの新しいタグ(同じ形)と、固定したタグが今指す digest を調べる(#4 と #5 の新しい土台の元)。
 */
final readonly class ImageUpdateFinder
{
    public function __construct(
        private RegistryClient $registry,
        private ImageReader $reader,
    ) {}

    /**
     * @param  list<ImageSpec>  $specs
     */
    public function findAll(array $specs): ImageUpdateResults
    {
        $updates = [];
        $failures = [];

        foreach ($specs as $spec) {
            try {
                $updates[$spec->label] = $this->find($spec);
            } catch (Throwable $exception) {
                $failures[$spec->label] = Unconfirmeds::from($spec->label, $exception)->reason;
            }
        }

        return new ImageUpdateResults($updates, $failures);
    }

    /**
     * @throws UnconfirmedException
     */
    public function find(ImageSpec $spec): ImageUpdate
    {
        $ref = $this->reader->read($spec);

        if ($ref->digest === null) {
            throw new UnconfirmedException("{$spec->written}:{$ref->tag} に digest が書かれていない");
        }

        $newest = (new TagForm($ref->tag, $spec->seriesPattern))->newest($this->registry->tags($spec->registry, $spec->path));

        return new ImageUpdate(
            $spec->written,
            $ref->tag,
            $ref->digest,
            $newest,
            $newest === null ? null : $this->registry->digest($spec->registry, $spec->path, $newest),
            $this->registry->digest($spec->registry, $spec->path, $ref->tag),
        );
    }
}
