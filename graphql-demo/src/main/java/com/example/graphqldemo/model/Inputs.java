package com.example.graphqldemo.model;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotNull;
import jakarta.validation.constraints.Size;
import java.time.LocalDate;

/** GraphQL input types, bound from the "input" argument. Bean Validation rules are enforced on mutations. */
public final class Inputs {

    private Inputs() {
    }

    public record BookFilter(Genre genre, String titleContains, Integer minPages) {
    }

    public record AuthorInput(@NotBlank String name, String email, LocalDate born) {
    }

    public record BookInput(@NotBlank @Size(max = 200) String title, @NotNull Genre genre, LocalDate published,
                            @Min(1) Integer pages, @NotNull Long authorId) {
    }

    public record BookUpdateInput(@Size(min = 1, max = 200) String title, Genre genre, LocalDate published,
                                  @Min(1) Integer pages) {
    }

    public record ReviewInput(@NotNull Long bookId, @Min(1) @Max(5) int rating, String comment) {
    }
}
