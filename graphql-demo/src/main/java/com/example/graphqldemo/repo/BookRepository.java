package com.example.graphqldemo.repo;

import com.example.graphqldemo.model.Book;
import java.util.Collection;
import java.util.List;
import org.springframework.data.domain.Limit;
import org.springframework.data.domain.ScrollPosition;
import org.springframework.data.domain.Window;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.JpaSpecificationExecutor;

public interface BookRepository extends JpaRepository<Book, Long>, JpaSpecificationExecutor<Book> {

    List<Book> findByAuthorIdIn(Collection<Long> authorIds);

    List<Book> findByTitleContainingIgnoreCase(String text);

    /** Keyset scrolling, adapted to a Relay connection by Spring GraphQL. */
    Window<Book> findAllByOrderByIdAsc(ScrollPosition position, Limit limit);
}
