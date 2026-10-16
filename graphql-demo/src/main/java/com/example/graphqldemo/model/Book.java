package com.example.graphqldemo.model;

import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import java.time.LocalDate;

@Entity
public class Book {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;
    private String title;
    @Enumerated(EnumType.STRING)
    private Genre genre;
    private LocalDate published;
    private Integer pages;
    // plain foreign key: the author is resolved by the GraphQL layer (DataLoader), not by JPA
    private Long authorId;

    protected Book() {
    }

    public Book(String title, Genre genre, LocalDate published, Integer pages, Long authorId) {
        this.title = title;
        this.genre = genre;
        this.published = published;
        this.pages = pages;
        this.authorId = authorId;
    }

    public Long getId() { return id; }
    public String getTitle() { return title; }
    public Genre getGenre() { return genre; }
    public LocalDate getPublished() { return published; }
    public Integer getPages() { return pages; }
    public Long getAuthorId() { return authorId; }

    public void setTitle(String title) { this.title = title; }
    public void setGenre(Genre genre) { this.genre = genre; }
    public void setPublished(LocalDate published) { this.published = published; }
    public void setPages(Integer pages) { this.pages = pages; }
}
