package com.example.graphqldemo.repo;

import com.example.graphqldemo.model.Author;
import java.util.List;
import org.springframework.data.jpa.repository.JpaRepository;

public interface AuthorRepository extends JpaRepository<Author, Long> {

    List<Author> findByNameContainingIgnoreCase(String text);
}
