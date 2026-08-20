<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

final class DefaultClassificationSeeds
{
    /** @return list<ClassificationSeed> */
    public static function bookTypes(): array
    {
        return self::seeds([
            "book_type.reading_book" => "Leesboek",
            "book_type.cookbook" => "Kookboek",
            "book_type.study_book" => "Studieboek",
            "book_type.knowledge_book" => "Kennisboek",
            "book_type.comic_book" => "Stripboek",
            "book_type.picture_book" => "Prentenboek",
            "book_type.travel_guide" => "Reisgids",
            "book_type.dictionary" => "Woordenboek",
            "book_type.photo_book" => "Fotoboek",
        ]);
    }

    /** @return list<ClassificationSeed> */
    public static function genres(): array
    {
        return self::seeds([
            "genre.adventure" => "Avontuur",
            "genre.fantasy" => "Fantasy",
            "genre.science_fiction" => "Sciencefiction",
            "genre.thriller" => "Thriller",
            "genre.detective_mystery" => "Detective / Mystery",
            "genre.horror" => "Horror",
            "genre.romance" => "Romance",
            "genre.historical" => "Historisch",
            "genre.literature" => "Literatuur",
            "genre.humor_satire" => "Humor / Satire",
            "genre.dystopia" => "Dystopie",
            "genre.magical_realism" => "Magisch realisme",
        ]);
    }

    /** @return list<ClassificationSeed> */
    public static function subjects(): array
    {
        return [];
    }

    /**
     * @param array<string, string> $values
     * @return list<ClassificationSeed>
     */
    private static function seeds(array $values): array
    {
        $seeds = [];

        foreach ($values as $key => $name) {
            $seeds[] = new ClassificationSeed(
                new ClassificationSeedKey($key),
                new ClassificationTermName($name)
            );
        }

        return $seeds;
    }

    private function __construct()
    {
    }
}
